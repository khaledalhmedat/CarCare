# Regression Checklist — Car Care Backend

Manual checks to run before a demo. Automated coverage exists for many of these
(see `tests/Feature`); items marked **[auto]** have a passing feature test, items
marked **[manual]** are verified by hand / Postman.

Run automated tests with an isolated DB (never the dev DB):

```
php artisan test        # uses carcare_test (see phpunit.xml)
```

---

## 1. Auth
- [ ] **[auto]** Register returns success + token.
- [ ] **[auto]** Login returns token.
- [ ] **[auto]** `GET /api/auth/me` requires authentication (401 without token).
- [ ] **[manual]** Logout revokes the current token.

## 2. OTP password reset
- [ ] **[auto]** Forgot-password returns the same generic message for known and unknown emails.
- [ ] **[auto]** OTP is never returned in the response.
- [ ] **[auto]** Invalid OTP returns 422.
- [ ] **[auto]** Verify OTP → reset password → login with new password works.
- [ ] **[manual]** A real OTP email is delivered when SMTP is configured.

## 3. Google login
- [ ] **[manual]** Deferred until Flutter Google Sign-In is ready.
- [ ] **[manual]** Server needs `GOOGLE_CLIENT_ID`; without it `/api/auth/google` returns 503.

## 4. Admin dashboard
- [ ] **[auto]** Non-admin gets 403; unauthenticated gets 401.
- [ ] **[auto]** Summary, operations, revenue return 200 for admin.

## 5. Provider approval
- [ ] **[auto]** Admin route requires admin; non-admin blocked; invalid type → 422.
- [ ] **[auto]** Admin can list pending providers.
- [ ] **[manual]** Approve / reject / suspend / reactivate transitions update status + timestamps and notify the provider.

## 6. Billing
- [ ] **[auto]** Non-admin blocked from billing settings; admin can list settings, invoices, provider-status.
- [ ] **[manual]** Create billing setting; generate invoice; issue; mark paid manually; cancel.
- [ ] **[manual]** Cannot pay an invoice twice; cannot issue a cancelled/paid invoice.

## 7. Advertisements
- [ ] **[auto]** Public active ads endpoint returns 200.
- [ ] **[auto]** Admin index requires admin; admin can list ads.
- [ ] **[manual]** Create ad with image upload; activate/deactivate; image replacement deletes old file.

## 8. Reports
- [ ] **[auto]** Overview, operations, providers, financial, billing, advertisements return 200 for admin; non-admin blocked.

## 9. Notifications
- [ ] **[auto]** Unauthenticated blocked; user can list; unread-count shape correct; mark-all-as-read clears unread.
- [ ] **[manual]** Mark-as-read and delete a single notification (owner only).
- [ ] **[manual]** Reverb real-time delivery checked separately with a live Reverb service.

## 10. Customer flows
- [ ] **[manual]** Vehicles CRUD (owner-scoped).
- [ ] **[manual]** Maintenance request create → quotations → accept → complete.
- [ ] **[manual]** SOS request create → track.
- [ ] **[manual]** Fuel order (emergency) create → track.
- [ ] **[manual]** Car wash booking create → rate.
- [ ] **[manual]** Shop/products/cart/orders → order → track delivery.

## 11. Provider flows
- [ ] **[manual]** Technician SOS: available → accept → status updates.
- [ ] **[manual]** Fuel provider: available orders → accept → status.
- [ ] **[manual]** Car washer: bookings → accept → status.
- [ ] **[manual]** Shop owner: products; incoming orders → accept → status.

## 12. Reliability
- [ ] **[manual]** Only an open SOS can be accepted; two technicians cannot both claim it.
- [ ] **[manual]** Only a pending fuel order can be accepted; completing it twice does not create a duplicate fuel log.
- [ ] **[manual]** Car wash status is forward-only (cannot complete a cancelled/completed booking).

## 13. Security
- [ ] **[auto]** Admin routes blocked for non-admin (dashboard, reports, billing, provider approval).
- [ ] **[auto]** Unauthenticated protected routes return 401.
- [ ] **[manual]** A provider cannot access another provider's orders/bookings/jobs.
- [ ] **[manual]** A user cannot access another user's vehicles/orders/notifications.

## 14. Scalability
- [ ] **[auto]** `GET /api/health` returns an `instance` field (defaults to `app`).
- [ ] **[manual]** app-1 / app-2 request distribution verified by the server owner through Apache (see `docs/scalability/apache-load-balancing-poc.md`).

## 15. Demo readiness
- [ ] **[manual]** Seed/demo accounts exist (admin + one of each provider type + a customer).
- [ ] **[manual]** Main APIs verified in the Postman collection.
- [ ] **[manual]** No `.env` / secrets exposed in responses, logs, or the repo.

---

### Notes
- Automated tests run against the **`carcare_test`** database (configured in
  `phpunit.xml`) using `RefreshDatabase`. They never touch the development
  database. Create the test DB once if missing: `CREATE DATABASE carcare_test;`.
- Email, broadcasting, and Google are **faked/disabled** in tests (array mailer,
  null broadcaster, no real Google calls).
- State-transition and multi-actor concurrency flows are covered by manual checks
  here (they need richer fixtures than the smoke tests build); the underlying
  guards were verified during Stage 9.
