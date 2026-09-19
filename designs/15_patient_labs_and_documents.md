# Patient lab and clinical document screens

Files remain inaccessible until quarantine validation and malware scanning succeed. Download/view actions always reauthorize and use ephemeral access; the app does not persist medical documents into Drift by default.

## Screen 1 — Lab requests

**Maturity:** Planned, Phase 07. **Route concept:** `/labs`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a Material 3 lab-request list with Current/Past filtering, lifecycle-rich cards, provenance labels, and next actions.

- **Purpose:** Show each requested lab and its lifecycle with clear provenance.
- **Layout:** Current/Past filters, status summary, and request cards with requesting doctor/date, item count or approved safe summary, source (`upload` or `physical delivery`), and next action.
- **Content/actions:** Open request, start upload, mark physical delivery, view available result, and load more.
- **States:** Requested, upload requested, quarantined/processing, uploaded, patient-marked delivered, doctor-confirmed, reviewed, rejected, and action unavailable.
- **Accessibility:** Every status has plain-language description and icon. Cards announce pending actions and do not rely on color; long lab names wrap at large text.

## Screen 2 — Secure lab upload

**Maturity:** Planned, Phase 07. **Route concept:** `/labs/:id/upload`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a secure file-upload screen with request identity, accepted type/size guidance, selected file metadata, progress, cancel, and scan-status variants.

- **Purpose:** Select and send an approved PDF/image to one own lab request through a purpose-bound upload intent.
- **Layout:** Request identity, accepted type/size guidance, Choose file panel, selected safe metadata, checksum/progress, and Upload/Cancel actions.
- **Content/actions:** Open native picker, precheck declared size/type, upload, cancel, complete intent, or retry a new/versioned attempt after safe rejection.
- **States:** No file, selected, uploading, cancelled, completing, quarantined, scanning, available, rejected type/size, scan retryable/permanent, expired intent, and offline.
- **Privacy/accessibility:** No raw path/object key/signed URL. Permission denial and picker cancellation are distinct. Progress is announced at meaningful intervals, not continuously.

## Screen 3 — Lab request detail and delivery

**Maturity:** Planned, Phase 07. **Route concept:** `/labs/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a lab detail with provenance-aware lifecycle timeline, upload attempts, Mark Delivered warning, and current allowed action.

- **Purpose:** Explain exactly what was requested, what the patient reported, and what the doctor confirmed/reviewed.
- **Layout:** Status timeline, request summary, files/attempts, patient and doctor provenance labels, and current permitted actions.
- **Content/actions:** Upload/retry, Mark Delivered with warning, open current available file, and view doctor-reviewed state.
- **States:** Requested, patient-reported delivered, doctor-confirmed received, reviewed, processing, rejected, stale version, and denied.
- **Rules:** “Mark Delivered” explicitly says it is patient-reported and does not equal doctor confirmation. Patient cannot call confirm/review actions; no hidden controls exist.

## Screen 4 — Reports, sick leave, and referrals list

**Maturity:** Planned, Phase 07. **Route concept:** `/documents`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a reports/sick-leave/referrals list with type filters, current/corrected markers, and authorized open actions.

- **Purpose:** Browse finalized patient-readable clinical documents independently from raw lab uploads.
- **Layout:** Type filters, current/new items, cards with document type, doctor, encounter/date, current/corrected marker, and availability.
- **Content/actions:** Open current document, view version history, download through ephemeral access if permitted, and open related encounter.
- **States:** Empty, finalized, new, corrected, rendering, available, unavailable/expired access, and denied.
- **Safety/accessibility:** Do not show draft documents. File names are safe and document type has text/icon. The list is not cached offline unless a later reviewed requirement authorizes it.

## Screen 5 — Clinical document viewer

**Maturity:** Planned, Phase 07. **Route concept:** `/documents/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a secure clinical-document viewer with version/provenance header, sandboxed document area, accessible summary, and minimal action bar.

- **Purpose:** Read the exact current report, sick leave, or referral artifact with identity/version provenance.
- **Layout:** Document title, current/corrected banner, doctor/encounter/date/version/hash-safe identity, sandboxed PDF/image viewer, accessible text summary when available, and action bar.
- **Content/actions:** View current/original version under policy, download/share only through approved platform behavior, zoom, and return.
- **States:** Authorizing, loading, current, corrected, token expired/retry, unsupported/rejected file, access revoked, and viewer failure.
- **Security/accessibility:** Restrictive content handling prevents scripts/navigation. Do not expose object key or raw URL. Screen readers receive document metadata and an alternate path when embedded PDF content is inaccessible.

## Screen 6 — Document correction history

**Maturity:** Planned, Phase 07. **Route concept:** nested within document detail.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate an append-only document correction history with prominent current version and preserved prior artifacts.

- **Purpose:** Make append-only correction history understandable while defaulting to the latest current version.
- **Layout:** Current-version summary, chronological versions with correction reason category, doctor/time, prior artifact availability, and changed-version markers.
- **Content/actions:** Open current or allowed prior artifact, return to encounter, and acknowledge a correction notification.
- **States:** No correction, corrected/current, original preserved, prior artifact unavailable under policy, notification unread, and access denied.
- **Rules:** Never imply that the original was overwritten or deleted. Do not expose internal audit/device data or free-form private correction rationale beyond approved patient copy.

## Sources

Phase [07](../docs/phases/07_labs_files_reports_and_referrals.md).
