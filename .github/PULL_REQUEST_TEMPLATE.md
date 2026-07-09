## What & why
Brief description of the change and the motivation.

## Type
- [ ] Bug fix
- [ ] New adapter / capability
- [ ] Hardening / performance
- [ ] Docs / Blueprint

## Checklist
- [ ] `capability-core` tests pass (`composer test`) if the core is touched.
- [ ] Responses still validate against the **published** UCP schemas (`tools/validate-ucp.php`).
- [ ] The **anonymous / public-only** filter is preserved — no customer data, no B2B/group prices.
- [ ] Read-only preserved (no write, cart, or checkout).
- [ ] If a capability/transport is added: declared in the manifest **only once the endpoint actually responds** (a manifest is a promise).
- [ ] Verified on **PrestaShop 8 and 9** when the module is touched.

## Notes for reviewers
Anything specific to look at.
