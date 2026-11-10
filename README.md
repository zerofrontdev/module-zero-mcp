# Zero_Mcp — Your store as a connector in Claude

The store becomes a connector (an MCP server) for the Claude app on a
phone or computer, claude.ai, Claude Desktop and Claude Code: Claude thinks
with the admin's own Claude subscription, the store does the work, no API
key. The AI Assistant's tools, commands and the modules' documentation,
with the admin user's own role, every change proposed first and confirmed
in the conversation, all of it journaled and undoable.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-mcp
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-ai-assistant` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Connecting

1. Stores > Configuration > Zero > AI Assistant > **Claude Connector** >
   Enabled. The store needs HTTPS and a public address.
2. In Claude: Settings > Connectors > **Add custom connector**, URL
   `https://your-store/mcp`.
3. Claude opens the store's connect page. It asks no password: the admin
   allows the connection in the Magento admin, behind the admin's own
   sign-in, two-step verification, reCAPTCHA and lockout. Signing in to
   the admin in the same browser (within 10 minutes) opens **Connect
   Claude** with the request; from another browser or device, the code on
   the connect page goes into System > Other Settings > Claude
   Connections. Allow or Deny. (The admin user needs the AI Assistant
   resource in its role; the role's AI Assistant areas, if any are
   ticked, limit the connection the same way they limit the chat.)
4. Claude is connected. A connector added on claude.ai is there in the
   phone app too.

A store that uses the assistant from Claude only can switch the admin chat
off (Zero > AI Assistant > **Chat in the Admin** = No): the page and the
menu item go, the connector stays. Claude thinks with the admin's own
subscription, so the store needs no AI provider for that; only bulk product
texts (bulk_write_products) are written by the store's own model.

System > Other Settings > **Claude Connections** lists every connection (admin
user, Claude client, last call, calls), with **Revoke**. Each new connection is
told at once (**Notify of New Connections**, on by default): a message in the
admin's notifications and an email (who, which app, when, from which IP and
browser) to the store's General Contact or the address set. A connection lasts
Connection Lifetime (90 days), then the admin allows it again.

The connect page is public, so by default it does not link to the admin
(that would show the admin's address to anyone who opens it); **Link to
the Admin on the Connect Page** = Yes adds the link.

## What Claude gets

- **Tools**: the AI Assistant's tools the role allows. A reading one runs
  at once. A changing one (`update_product`, `create_product`,
  `bulk_write_products`, `ship_order`…) only *proposes*: it returns a
  `change_id` and what would be done, Claude shows it, and
  `confirm_change` runs it once the admin agrees in the conversation.
  Kinds listed in **Confirm in the Admin** (by default: cancelling,
  invoicing and shipping orders, order comments, quote answers and return
  updates that email customers, deleting waybills, settings, sending
  fiscal receipts, and a product's price, special price and qty, also when
  scheduled) are never run by `confirm_change`. An entry `tool:field`
  (`update_product:price`, `schedule_change:special_price`; the multiselect
  shows it as "update_product: price") covers only the changes of that tool
  that give that field (`schedule_change`'s under its `fields`): a product's
  new name is confirmed in the conversation, its new price in the admin.
  There is no refund tool, so refunds are not in the list. When a stored
  list is the old default exactly, `setup:upgrade` replaces it with the new
  one (`Setup\Patch\Data\ConfirmMoneyInAdmin`); a list the store changed is
  left alone. The admin confirms those kinds in System >
  Other Settings > Claude Connections > **Changes Waiting for You**
  (Claude gives the link; it survives the admin's sign-in), so a prompt
  hidden in store data cannot make Claude confirm them. A change is
  confirmed once (two confirms at once run it once), only through the
  connection that proposed it, and within a day (then it is "expired").
  `cancel_change`, `undo_change` and `list_changes` (newest first, 20 by
  default, up to 100) complete the set. `undo_change` puts a product change
  back, or queues a whole bulk job's old texts (the answer is `undoing`
  with `job_url`, the job's page in the admin, which works with Chat in the
  Admin off and survives the sign-in); any other change can't be undone,
  and the answer is an error that says so, as is an undo of a change undone
  already or being undone. Claude's own permission prompt
  sits on top: a tool that isn't read-only asks before it runs.
- **Photos**: `request_photos` gives a link; opened on the phone, it takes
  or picks up to 10 photos (30 minutes, the link's key is the only
  credential). `get_photos` returns them to Claude as images with their
  upload ids, for `create_product` and `update_product`, a category's
  image (`save_category`) or a page or block (`{{upload:N}}` in the HTML
  of `save_cms_page` / `save_cms_block`). So "create a product from
  photos" works from the phone: Claude asks for the link, the admin takes
  the photos, Claude writes the product and proposes it.
- **Prompts**: the assistant's commands (`/describe`, `/orders`…) as
  prompts Claude's apps offer; a module's own commands come along.
- **Resources**: every Zero module's README and the theme's docs
  (`zero://docs/<Module>`), so "how do I set up X" is answered from the
  documentation.
- `connection_info`: who the connection is and what it may do.

Everything goes through the same journal as the admin chat: a connection
has a conversation of its own in the AI Assistant (its proposed changes
with Confirm, Undo, the bulk jobs' progress), and System > AI Assistant >
Connections shows the last call.

## How it works

- **Transport**: Streamable HTTP, stateless: every POST to `/mcp` is a
  JSON-RPC message with a bearer token. No session, no event stream.
- **OAuth 2.1**: an unauthenticated call gets `401` with
  `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource/mcp"`;
  the protected resource metadata names the store as the authorization
  server; `/.well-known/oauth-authorization-server` lists the endpoints.
  Claude registers itself (Dynamic Client Registration, RFC 7591; only
  Claude's callback `https://claude.ai/api/mcp/auth_callback`, loopback
  addresses for Claude Code, and URIs listed in Other Allowed Redirect
  URIs are accepted), sends the admin to `/zero_mcp/oauth/authorize`, and
  exchanges the code at `/zero_mcp/oauth/token` with PKCE (S256, required).
  The authorize page keeps the request (`Model\Oauth\Pending`, 10 minutes,
  only hashes of its two secrets: the browser's httponly cookie and the
  code shown) until the admin allows it in the admin
  (`Controller\Adminhtml\Oauth\Consent`; `Observer\PendingConsent` takes
  an admin who signed in with that cookie there). Allow or Deny takes the
  request once.
  Public clients only, no client secret. Tokens: an access token (Access
  Token Lifetime, 24 hours) and a refresh token (Connection Lifetime),
  rotated on every refresh; only their hashes are stored. No password is
  ever typed outside the admin. A code is exchanged once even when two
  requests race; an old refresh token presented again (more than 30
  seconds after its rotation) ends the connection, as someone else has it.
- **The admin user as they are now**: every call and every refresh checks
  that the user is active and the role still has the AI Assistant;
  deactivating or deleting the user ends their connections at once
  (`Observer\RevokeUserConnections`).
- **Roles**: the connection's tools and commands are the ones the admin
  user's role allows (`Model\Acl\UserAuthorization`: the admin ACL for
  that user, outside an admin session), checked on every call, on
  `confirm_change` and `undo_change` too.
- **Rate limit**: Calls per Hour per Connection (300). Client registrations
  (`/zero_mcp/oauth/register`, open to anyone): Registrations per Hour per
  Address (30) per clock hour, and ten times that for the whole store as a
  backstop; every POST counted (one refused by its address's limit doesn't
  use the store's); more get HTTP 429 `temporarily_unavailable` with
  `Retry-After` (`Model\Oauth\RegistrationLimit`, counted in the cache).
  Clients that never got a connection are purged after 7 days (AI
  Assistant's nightly purge).
  The address is one the client cannot set (`Model\Oauth\ClientAddress`),
  not Magento's `RemoteAddress`: `app/etc/di.xml` makes that read the first
  `X-Forwarded-For` entry, which the client writes, so a new forged header
  per request would get a new counter. Instead:
  - PHP-FPM reached directly by the visitor (no proxy of your own in front):
    `REMOTE_ADDR`.
  - Through the store's own proxies (`REMOTE_ADDR` loopback, for example
    public nginx :443 → Varnish → nginx 127.0.0.1:8080 → PHP-FPM):
    `X-Real-IP`, which the public nginx block sets to its own peer
    (`proxy_set_header X-Real-IP $remote_addr`, overwriting any the client
    sent); Varnish and :8080 listen on loopback only. Assumption: every
    proxy that ends on loopback sets X-Real-IP like that. Behind a CDN such
    as CloudFront that peer is the CloudFront edge, so the limit is
    per edge (its viewers together), and the store-wide ceiling is what
    bounds many addresses; Claude itself registers from Anthropic's servers.
  - No usable address (loopback without X-Real-IP, a value that isn't an IP
    address): the store-wide ceiling only.
- **Routes**: `/mcp` and the two `/.well-known/…` documents are served by
  `Controller\Router`; the rest lives under `/zero_mcp/…`. Behind Varnish
  nothing is cached: the endpoints send `Cache-Control: no-store`, and
  requests with `Authorization` bypass the cache anyway.

Other MCP clients (ChatGPT, Cursor, an in-house agent) work the same way
when they speak Streamable HTTP with OAuth 2.1 and PKCE; add their
callback to Other Allowed Redirect URIs if it isn't a loopback one.

## What keeps it safe

- A connection is an admin user: nothing more than their role, and every
  change proposed, confirmed, journaled and undoable, as in the admin chat.
- What tools return is data, never instructions. A few changing tools
  email a customer (an order comment with notify, a return's update, a
  quote's answer); like every change, only after confirmation.
- The connection is allowed in the admin, never with a password on the
  storefront: the admin's sign-in, two-step verification and lockout
  apply, and the consent page says who asks and where the browser goes
  back to.
- The photo link's key opens one draft for 30 minutes and takes images
  only (checked by content), into the connection's own conversation.
- Revoke in Connections ends a connection at once.

Trap: the new connection email (`view/adminhtml/email/new_connection.html`)
renders in the adminhtml area, where Magento_Email has no header/footer
templates. Including `design/email/header_template` or `footer_template`
there throws "Template file 'header.html' is not found" and Magento sends an
error text instead of the notice (prod, 2026-10-11 to 2026-10-30). Keep
admin-area templates self-contained;
`Zero/Core/Test/Unit/Email/AdminhtmlEmailTemplatesTest.php` checks every
Zero module.

## Install

```
composer require zerofront/module-zero-mcp
bin/magento setup:upgrade
```

Needs Zero_AiAssistant. Magento Open Source 2.4.7–2.4.9, PHP 8.2–8.4.
Tests: `Test/Unit/Model/` (PKCE and codes, clients and redirect URIs,
the waiting request's secrets, the server's dispatch, the connection's
tool list, undo and confirm answers, list_changes limit, Confirm in the
Admin field entries, the registration limit and its address, metadata cap),
`Test/Unit/Controller/` (429 with Retry-After), `Test/Unit/Plugin/` (the
job link through the sign-in), `Test/Unit/Setup/` (ConfirmMoneyInAdmin);
e2e `dev/tests/zero-e2e/tests/mcp-connector.spec.ts`.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
