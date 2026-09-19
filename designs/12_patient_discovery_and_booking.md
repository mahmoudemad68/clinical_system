# Patient doctor discovery and booking screens

Discovery is available without precise location. Distance is display-only; standard ranking is earliest availability, then rating. A slot token is a short-lived hint, never a reservation.

## Screen 1 — Doctor search

**Maturity:** Planned, Phase 08. **Route concept:** `/find-doctor`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a Material 3 doctor-search screen with prominent search, specialty filters, Nearby control, result cards, and bottom navigation.

- **Purpose:** Search approved doctors manually by name and specialty.
- **Layout:** Search field, specialty/filter chips, optional Nearby toggle, sort explanation, result list, and map/list switch when location is available.
- **Content/actions:** Enter Arabic/English text, select specialty, request nearby results with just-in-time permission, clear filters, load more, and refresh stale availability.
- **States:** Initial suggestions, loading, no results, permission denied/restricted, location unavailable, stale availability, query invalid/rate limited, offline, and retryable failure.
- **Accessibility/privacy:** Normalize search safely while preserving display text. Result accessible names include doctor, specialty, next availability, rating, and location count. Precise coordinates never enter URLs, analytics, or persistence.

## Screen 2 — Nearby doctors map and list

**Maturity:** Planned, Phase 08. **Route concept:** `/find-doctor/nearby`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a map-plus-bottom-sheet screen with a fully usable list fallback, location-permission rationale, and selected doctor/location card.

- **Purpose:** Compare public clinic destinations geographically after explicit permission.
- **Layout:** Collapsible map plus synchronized result sheet/list; selected pin card shows doctor/location, distance, next availability, and View profile. List-only fallback is complete.
- **Content/actions:** Request current location once, adjust bounded radius, select result, recenter, switch list, and open external directions with disclosure.
- **States:** Permission rationale, granted, denied forever, service off, imprecise/unavailable, loading, no nearby results, stale, and map provider failure.
- **Privacy/accessibility:** No background tracking. Send the least precise usable point and discard it after response. Map has equivalent list/navigation and does not encode ranking by distance.

## Screen 3 — Doctor profile

**Maturity:** Planned, Phase 08. **Route concept:** `/doctors/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a scrollable doctor profile with professional header, rating, locations, offerings, reviews, and sticky Book action.

- **Purpose:** Evaluate one approved doctor using public professional facts.
- **Layout:** Header with name/specialty/rating; sections for profile, locations, appointment types/prices, next availability, and pseudonymous reviews; sticky Book action.
- **Content/actions:** Choose location, read reviews, view price/duration/payment-at-clinic, open directions, and continue to offering/slots.
- **States:** No reviews, rating not ready, listing updated, availability stale, location inactive, no offerings, loading, and not found/denied.
- **Safety/accessibility:** No personal contact or patient identity in reviews. Currency/time are exact and localized. Reviews render as safe plain text with long-content controls and clear moderation status where applicable.

## Screen 4 — Location and appointment type selection

**Maturity:** Planned, Phase 03. **Route concept:** `/doctors/:id/book`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate the first booking step with progress indicator, selectable location cards, appointment-type cards, exact price/duration, and sticky Continue.

- **Purpose:** Select the exact location and offering before requesting slots.
- **Layout:** Step indicator, location cards with address/map/distance, then appointment-type cards with duration, EGP price, active state, and next availability.
- **Content/actions:** Choose one location, choose one type, open directions, Continue, or Back without losing safe selections.
- **States:** Loading, unavailable location/type, price/version updated, no active offering, stale summary, and location permission absent.
- **Rules:** Every value comes from the current public projection. The app never submits its displayed price as authority or treats distance as ranking.

## Screen 5 — Date and slot selection

**Maturity:** Planned, Phase 03. **Route concept:** booking step 2.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate the second booking step with horizontal dates, large grouped slot buttons, availability freshness, selected-slot summary, and sticky Continue.

- **Purpose:** Choose one available Cairo-local slot from a bounded horizon.
- **Layout:** Horizontal date picker/calendar, grouped slot buttons by morning/afternoon/evening, selected-slot summary, and availability `as_of`.
- **Content/actions:** Change date, select slot, refresh, continue, and handle a lost slot while retaining doctor/location/type.
- **States:** Loading, no slots, stale, slot selected, token expired, `SLOT_UNAVAILABLE`, rate limited, timezone ambiguity handled by server, and offline.
- **Accessibility:** Slot buttons announce complete date/time and duration, have large touch targets, and do not rely on color. DST/timezone details remain unambiguous in Arabic and English.

## Screen 6 — Booking review, confirmation, and result

**Maturity:** Planned, Phase 03. **Route concept:** booking step 3/result.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a booking review screen and a separate committed-result variant, keeping exact doctor/location/time/price facts and one distinct confirmation control.

- **Purpose:** Obtain explicit human confirmation for the exact appointment and reconcile one atomic booking outcome.
- **Layout:** Doctor/location/address, appointment type, Cairo date/time, duration, EGP price, pay-at-clinic method, cancellation summary, and distinct Confirm booking button; result replaces the action region.
- **Content/actions:** Confirm once, edit selection, cancel, view committed appointment, or reselect after conflict.
- **States:** Ready, submitting, unknown/reconciling with same idempotency key, booked, slot lost/expired, price/schedule changed, validation, and failure without booking.
- **Rules:** Never auto-select an alternative or claim success early. A duplicate tap returns the same appointment; conflict preserves upstream selections but requires a new explicit slot choice.

## Sources

Phases [03](../docs/phases/03_scheduling_availability_and_booking.md) and [08](../docs/phases/08_patient_experience_discovery_reviews_and_localization.md).
