# Fix post-Phase 7 schema integrity and bilingual workspace UX

Retained browser fixtures could report all migrations applied while missing critical Phase 7 bindings. Browser preparation now verifies schema independently and offers an explicitly guarded disposable-only rebuild. Add drift/disabled-trigger/reset-refusal regression tests.

Unify permission-aware workspace navigation, surface shipment creation/pickups/rates, retain shipment context across language switches, expose localized field validation with focus, correct Arabic service labels and mixed-direction identifiers, and improve mobile team cards, availability copy, empty states and administrative filtering. Add a minimal tenant-scoped shipment directory summary without exposing addresses, financial costs or carrier credentials.

Validation and screenshot comparison: docs/POST_PHASE_7_QA_REPORT.md. Existing live carrier/payment/financial gates are unchanged. No production deployment, Phase 8 or automatic merge.
