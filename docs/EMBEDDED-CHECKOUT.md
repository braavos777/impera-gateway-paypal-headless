# Embedded PayPal checkout — storefront integration guide

For the storefront team integrating `impera-gateway-paypal-headless` ≥ 1.0.6 in
embedded (iframe) mode.

Reference implementation: `www/frontend` (Next.js + WooCommerce Store API).
File paths below point at it; every snippet is copied from working code.

---

## Context

In embedded mode the gateway does **not** redirect the buyer to PayPal. Instead
the storefront embeds a page served by the payment proxy, and the PayPal buttons
render inside that iframe. The two sides talk over a `MessageChannel` bridge.

The gateway renders no HTML of its own — `has_fields = false`, `payment_fields()`
is not overridden (`PayPalGateway.php:36-38`, `:61`). **The storefront owns the
iframe element, its size, and its lifecycle.** This is the work to be done on
your side; everything below describes the contract it has to meet.

Division of responsibility:

| | Who |
|---|---|
| Iframe element, layout, full-screen handling | **Storefront** |
| Bridge handshake + order payload | **Storefront** |
| The page inside the iframe, PayPal SDK, buttons | Proxy |
| Creating/capturing the PayPal order, money | Proxy + gateway, server-to-server |

Nothing the browser sends decides what is charged: at capture the gateway sends
WooCommerce's own order total, signed (`CaptureOrderDto.php:20-29`,
`PaymentProcessor.php:136-148`).

---

## 1. WooCommerce configuration

**WooCommerce → Settings → Payments → Impera Gateway PayPal.**

| Field | Value |
|---|---|
| Enable | on |
| **Proxy domain** | host only — `pay.example.com`. No scheme, no path, no port, no trailing slash |
| **Shop key id / secret** | the key issued for this shop on the proxy (*Shop keys*, gateway `paypal_redirect`). `IMPERA_PPH_KEY_ID` / `IMPERA_PPH_SECRET` in `wp-config.php` override the fields |
| **Checkout flow** | **Embedded buttons — a headless storefront frames the proxy** |
| Sandbox | on for testing - a stored setting; nothing in the URL or the storefront can switch it |
| Store currency | must be one of `IMPERA_PPH_AVAILABLE_CURRENCIES` (`bootstrap.php:21-25`) |

The method **hides itself** rather than failing later if any of these is wrong
(`PayPalGateway.php`, `is_available()`): empty proxy domain, no shop key, unsupported
currency. If PayPal is missing from the checkout, check those three first.

**Also required, on the proxy, by the provider:** this shop's domain (the
WordPress host, without `www.`) must be listed under **PayPal Redirect → Allowed
Domains** by its **exact** host — a `*.example.com` wildcard does not cover the
embedded flow, and an empty list allows nothing. Without it the proxy does not
serve the frame.

**The shop's domain never reaches the browser.** The iframe URL carries a one-time
`session` token (64 hex, 30 minutes) that the shop's server opened on the proxy by a
call signed with its own key; the proxy keeps which shop, which environment and which
currency the page is for. The URL names no shop and cannot choose sandbox. The proxy sends no `frame-ancestors` and prints no origin into
the frame; the frame hands its bridge to whatever page is its `window.parent`.

---

## 2. Getting the iframe URL

```
POST /wp-json/impera-gateway-paypal-headless/v1/paypal/pp-link
```

Public, no authentication, no nonce, **no request body** — the handler reads
nothing from the request (`HeadlessRoute.php:54-117`, `:120-133`).

```json
{
  "url": "https://pay.example.com/checkout?client=…&checkout=yes&intent=capture&currency=USD&session=<64 hex>",
  "origin": "https://pay.example.com"
}
```

Errors are `503` with a `code`:

| code | Meaning |
|---|---|
| `impera_pph_no_proxy_domain` | Proxy domain not configured |
| `impera_pph_no_shared_key` | Shop key not configured |
| `impera_pph_no_session` | The proxy did not open a checkout session (key, mode or domain refused - see the proxy log) |

Three rules:

1. **Call it once per checkout.** Every call mints a new `ProxyClient` token and opens
   a fresh proxy session. In the reference the fetch is guarded so that toggling
   the payment method back and forth does not reload the buttons
   (`CheckoutForm.tsx:127-143`).
2. **Do not rewrite the URL.** The `ProxyClient` token must stay exactly 30 characters
   or the proxy will not render the page (`HeadlessRoute.php:33-37`).
3. **Use the returned `origin` as-is.** Do not parse it back out of `url` — the
   bridge pins its handshake to this exact string.

### Proxy it through your own server

The browser calling WordPress directly is a cross-origin request, and the
WordPress base URL is usually a server-only setting. Reference:
`app/api/paypal/iframe-link/route.ts`.

```ts
const LINK_ROUTE = '/wp-json/impera-gateway-paypal-headless/v1/paypal/pp-link';

export async function POST() {
  let response: Response;
  try {
    response = await fetch(`${BASE}${LINK_ROUTE}`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
      redirect: 'manual',
    });
  } catch (error) {
    return NextResponse.json(
      { code: 'paypal_link_unreachable', message: 'PayPal is unreachable.' },
      { status: 502 },
    );
  }

  const data = (await response.json().catch(() => null)) as
    | { url?: unknown; origin?: unknown } | null;

  if (!response.ok) {
    return NextResponse.json(data ?? { code: 'paypal_link_error' }, { status: response.status });
  }

  // The origin becomes the postMessage target of the bridge, so it is checked
  // as strictly as the URL.
  if (
    typeof data?.url !== 'string' || !data.url.startsWith('https://') ||
    typeof data?.origin !== 'string' || !data.origin.startsWith('https://') ||
    !data.url.startsWith(data.origin)
  ) {
    return NextResponse.json({ code: 'paypal_link_invalid' }, { status: 502 });
  }

  return NextResponse.json({ url: data.url, origin: data.origin });
}
```

---

## 3. The bridge

`app/lib/gbridge.ts` is the storefront half. **Its wire protocol is shared byte
for byte with the copy the proxy serves into the iframe**
(`assets/js/paypal-redirect/gbridge.js` in `impera-proxy-payment`). Copy the file
as-is; do not reimplement it. The two halves only ever meet at runtime, and a
mismatch shows up as a checkout that silently never connects.

### Handshake

Only the handshake travels over `window.postMessage`. Everything after it goes
down a `MessageChannel` port, which is point-to-point and needs no origin check
of its own.

```ts
export function connectFrame(proxyOrigin: string, abort?: AbortSignal): Promise<Bridge> {
  return new Promise((resolve) => {
    const onMsg = (e: MessageEvent) => {
      // Exact string equality, scheme and port included: this is the one check
      // standing between the bridge and any other page in the tab.
      if (e.origin !== proxyOrigin) return;
      if (!e.data || (e.data as { __mj?: string }).__mj !== 'ready') return;

      window.removeEventListener('message', onMsg);
      const channel = new MessageChannel();
      (e.source as WindowProxy | null)?.postMessage({ __mj: 'connect' }, proxyOrigin, [channel.port2]);
      resolve(new Bridge(channel.port1));
    };

    window.addEventListener('message', onMsg);
    abort?.addEventListener('abort', () => window.removeEventListener('message', onMsg), { once: true });
  });
}
```

The frame pings `{ __mj: 'ready' }` every 200 ms until it is answered, so
attaching the listener late is safe. `connectFrame` never times out on its own —
the caller owns that decision via `abort`.

Never widen the origin check on the storefront side, and never `postMessage` to
`'*'` from it. (The frame side does ping `'*'`: it does not know the storefront's
origin, by design. The ping carries no data, and the frame accepts the port only
from its own `window.parent`.)

### Envelope

```ts
type Envelope =
  | { t: 'req'; id: number; method: string; args: unknown }
  | { t: 'res'; id: number; ok: true;  data: unknown }
  | { t: 'res'; id: number; ok: false; error: string }
  | { t: 'evt'; name: string; data: unknown };
```

`Bridge` gives you `request(method, args)`, `handle(method, fn)`, `emit(name, data)`,
`on(name, fn)` and `destroy()`.

### The calls

**The frame asks you (implement with `handle`):**

| Method | Returns |
|---|---|
| `impera-getOrder` | the order payload, or `null` when there is nothing payable |

**You tell the frame (send with `emit`):**

| Event | Data |
|---|---|
| `impera-sendOrder` | the order payload, or `null` |

**The frame tells you (subscribe with `on`):**

| Event | Data | What to do |
|---|---|---|
| `impera-approvedOrder` | `{ order_id, paymentSource }` | place the order — §5 |
| `impera-resizeIframe` | height in px | set the iframe height (reference adds 20 px) |
| `impera-makeFullIframe` | — | make the frame cover the page |
| `impera-makeNormalIframe` | — | return it to inline size |
| `impera-openFormReject` | — | buyer clicked before the form was usable; point them back at it |
| `impera-validationFail` | message | show it |
| `impera-orderError` | `{ message }` | show it |

Wiring, from `CheckoutForm.tsx:264-304`:

```ts
useEffect(() => {
  if (!frame) return;
  const abort = new AbortController();
  let live: Bridge | null = null;

  connectFrame(frame.origin, abort.signal).then((connected) => {
    if (abort.signal.aborted) { connected.destroy(); return; }
    live = connected;

    connected.handle('impera-getOrder', () => orderRef.current());

    connected.on('impera-approvedOrder', (data) => void placeApprovedOrder(data));
    connected.on('impera-resizeIframe', (h) => setFrameHeight(Number(h) + 20));
    connected.on('impera-makeFullIframe', () => setFrameFullScreen(true));
    connected.on('impera-makeNormalIframe', () => setFrameFullScreen(false));
    connected.on('impera-openFormReject', () =>
      setError('Please fill in your details before paying with PayPal.'));
    connected.on('impera-validationFail', (m) => setError(String(m)));
    connected.on('impera-orderError', (d) => setError((d as { message?: string })?.message ?? 'Could not process the payment.'));

    setBridge(connected);
  });

  return () => { abort.abort(); live?.destroy(); setBridge(null); };
}, [frame, placeApprovedOrder]);
```

**One bridge per frame.** Re-running this leaves the old `MessagePort` open and
the frame talking down a channel nobody reads. Note the handlers read the order
through a ref — they outlive the render that created them.

### Push *and* pull

The order is delivered twice over, on purpose:

- **push** — `emit('impera-sendOrder', order)` as soon as there is a total and
  again whenever it moves, so the pay button can show the price;
- **pull** — `handle('impera-getOrder', …)`, which the frame calls again on every
  click, so a total that moved while the buyer was deciding is still the one they
  are shown and the one the PayPal order is created for.

Implement both. Compare by value before emitting, or an identical payload is
pushed on every render (`CheckoutForm.tsx:306-316`).

---

## 4. The order payload

```ts
interface PayPalOrder {
  amount: { value: string; currency_code: string };
  items: { name: string; quantity: string; unit_amount: { currency_code: string; value: string } }[];
  description: string;
  address: Record<string, string>;        // address_line_1/2, admin_area_1/2, postal_code, country_code
  name: { given_name: string; surname: string };
  email_address: string;
  locale: string;
  shipping_preference: 'SET_PROVIDED_ADDRESS' | 'NO_SHIPPING' | 'GET_FROM_FILE';
  shipping?: Record<string, string>;      // name, line1, line2, city, state, postal_code, country
}
```

Rules that are not obvious:

- **`value` is a decimal string** with exactly the currency's minor-unit places —
  `"2967.00"`, never a number and never a hand-rolled `/100`. Store API prices
  arrive as minor-unit strings; use the currency's own `currency_minor_unit`
  (`format.ts:36-44`).
- **Send the cart as one line item worth the whole order**, with the real contents
  in `description`. PayPal requires `item_total + tax + shipping − discount` to
  equal the total to the cent; a WooCommerce total with coupons applied
  practically never does, and the proxy drops an items list that does not add up.
- `name` and `description` are truncated to **127** characters.
- `shipping_preference` is `SET_PROVIDED_ADDRESS` when the cart needs shipping,
  otherwise `NO_SHIPPING`. The address is already priced into the total, so PayPal
  must not let the buyer pick another one and move it afterwards. Avoid
  `GET_FROM_FILE`: a payment whose amount no longer matches the order is refused
  and left for manual review.
- **Return `null`, not a partial order**, when the cart is empty, the address is
  incomplete or the total is ≤ 0. `null` is a real answer — the frame turns it
  into `impera-openFormReject`, which is how a buyer who clicks too early is sent
  back to the form instead of into a broken popup.

### Price shipping *before* the buyer can approve

Once PayPal is approved the total is frozen. Push the address into the cart and
select a shipping rate while the buyer is still typing, debounced — not inside
the place-order handler the way a redirect gateway would
(`CheckoutForm.tsx:320-344`, `syncCart` at `:77-99`). Reference debounce: 700 ms.

---

## 5. Completing the order

On `impera-approvedOrder`, place the WooCommerce order with the approval
attached. Nothing has been charged yet — the gateway collects the money
server-to-server while this request is in flight, and that is also the only
moment anything verifies PayPal was asked for the right amount.

```ts
await storePost<CheckoutResponse>('/checkout', {
  billing_address: address,
  shipping_address: address,
  payment_method: 'impera_paypal_headless',
  payment_data: [
    { key: 'impera-paypal-payment-order-id', value: approved.order_id },
    { key: 'impera-paypal-payment-source',   value: approved.paymentSource ?? '' },
  ],
});
```

The two keys are mandatory and the names are exact. WooCommerce spreads
`payment_data` into `$_POST` (`StoreApi/Legacy.php:28`), which is where
`process_payment()` reads them (`PayPalGateway.php:131-137`, `:157-158`).

Flow selection is **by what arrived, not by the setting**
(`PayPalGateway.php:94-101`): a non-empty `impera-paypal-payment-order-id` takes
the capture branch; empty takes the redirect branch. So an accidental submit
without the approval silently creates a *second* PayPal order and abandons the one
the customer may already have approved.

Guard against that. In the reference the form returns early whenever PayPal is
the selected method, because pressing Enter in a text field still submits it
(`CheckoutForm.tsx:354-361`), and the normal Place-order button is not rendered at
all (`:518-536`). PayPal is started only by a button inside the frame.

Store API status codes describe processing state, not HTTP trouble: `200` success,
`202` pending, `400` failure, `500` error.

---

## 6. Rendering the frame

```tsx
{payingWithPayPal && (
  <div className={`pay-frame${frameFullScreen ? ' pay-frame--full' : ''}`}>
    {frame ? (
      <iframe
        id="impera-payment-paypal-area"
        title="PayPal"
        src={frame.url}
        style={{ height: `${frameHeight}px` }}
        referrerPolicy="no-referrer"
      />
    ) : <p className="muted">Loading PayPal…</p>}
  </div>
)}
```

```css
.pay-frame { margin-top: 12px; max-width: 560px; }
/* The height is set inline, from the frame's own impera-resizeIframe. */
.pay-frame iframe { display: block; width: 100%; border: 0; }
/* PayPal's popup overlay lives inside the frame, so the frame itself has
   to cover the page for it to be reachable. */
.pay-frame--full { position: fixed; inset: 0; z-index: 9999; margin: 0; max-width: none; background: rgba(0,0,0,.35); }
.pay-frame--full iframe { height: 100% !important; }
```

**The full-screen handling is not cosmetic.** PayPal renders its own overlay
*inside* the frame; without `impera-makeFullIframe` growing the frame to cover the
page, that overlay is clipped and the buyer cannot complete the payment.

Give the frame a sane starting height (480 px in the reference) — the first
`impera-resizeIframe` arrives only after the buttons have rendered.

Add `allow="payment"` if your app is itself framed or you use Payment Request.

---

## 7. Content-Security-Policy

If the storefront sets a CSP, the proxy origin must be allowed to be framed:

```
frame-src https://pay.example.com;
```

A missing `frame-src` gives you a **blank frame and no error in the UI** — only a
violation in the console. The proxy sends no `frame-ancestors`.

The `<iframe>` **must** carry `referrerpolicy="no-referrer"` (§6): otherwise the
storefront's URL reaches the proxy page and, through it, PayPal.

---

## 8. Checklist

- [ ] Gateway settings complete; **Checkout flow = Embedded buttons**
- [ ] Shop domain listed on the proxy under *PayPal Redirect → Allowed Domains* by its exact host
- [ ] `<iframe referrerpolicy="no-referrer">`
- [ ] Store currency supported; sandbox on for testing
- [ ] `pp-link` proxied through your own server, response validated, called **once** per checkout
- [ ] `gbridge.ts` copied unmodified; origin compared with `===`
- [ ] `impera-getOrder` handler **and** `impera-sendOrder` emit both implemented
- [ ] Amount is a decimal string in the currency's minor-unit places; one line item equal to the total
- [ ] Shipping priced before approval is possible
- [ ] `impera-resizeIframe` applied; `impera-makeFullIframe` genuinely covers the page
- [ ] `/checkout` sends both `payment_data` keys with exact names
- [ ] No other path can submit the order while PayPal is selected (Enter key included)
- [ ] One bridge per frame; `destroy()` on unmount
- [ ] CSP allows `frame-src <proxy origin>`

## 9. Troubleshooting

| Symptom | Cause |
|---|---|
| PayPal missing from the payment methods | Empty proxy domain / shared key, or unsupported currency (`PayPalGateway.php`, `is_available()`) |
| `pp-link` returns 503 | Read the `code`: `impera_pph_no_proxy_domain`, `impera_pph_no_shared_key` or `impera_pph_no_session` |
| Frame is empty (404) instead of the buttons | The session is unknown or expired (open the link within 30 minutes, one link per checkout), or the currency in the URL differs from the session's |
| Frame loads, handshake never happens | `connectFrame` given the wrong proxy origin — use `origin` from `pp-link` as-is; check two `gbridge` copies have not drifted |
| Frame is blank, nothing in the UI | CSP `frame-src` — check the console |
| Buttons render, every click is rejected | `impera-getOrder` returns `null`, or the shop was removed from the proxy's allowed list while the page was open |
| PayPal overlay is clipped / unclickable | `impera-makeFullIframe` not handled, or the full-screen CSS does not actually cover the page |
| Buttons reload when switching payment method | `pp-link` is being re-fetched; guard it |
| Order placed with no payment, or a duplicate PayPal order | The form was submitted without `impera-paypal-payment-order-id` — the gateway took the redirect branch |
| Approved amount ≠ order total | Shipping was calculated after approval, or the items list did not add up to the total |
| Nothing at all after a while, no error | Two `gbridge` versions that have drifted apart — re-copy from the proxy |

## 10. Verification

1. Sandbox payment end to end: buttons render → approve → order reaches Processing;
2. click **before** filling the form → `impera-openFormReject`, no PayPal order created;
3. change the cart while the frame is open → the next click prices the new total;
4. PayPal overlay opens full-screen and is clickable on mobile width;
5. cancel inside PayPal → checkout still usable, no orphan order;
6. WooCommerce order notes and the gateway's own log confirm one capture, matching amount and currency.
