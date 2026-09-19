# Doctor clinical workspace screens

Clinical UI is available only for an active, server-authorized consultation. The desktop may keep a bounded encrypted transient draft, but PostgreSQL remains the medical source of truth.

## Screen 1 — Current consultation control

**Maturity:** Planned, Phases 04–05. **Route concept:** persistent workspace header.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate a high-fidelity clinical shell/header state that makes current patient, access, location, connectivity, sync, and consultation controls unmistakable.

- **Purpose:** Make patient, encounter, location, consultation state, elapsed time, access, and connectivity unmistakable across every clinical sub-screen.
- **Layout:** Sticky identity banner with patient basics, appointment type/location, Start time, access badge, server version, and sync status. End Consultation is visually separated from content editing.
- **Content/actions:** Return to queue, open patient record, resume after approved reauthentication, end consultation, or view why completion is blocked.
- **States:** Active, saving, offline draft, conflict, access suspended, session unresolved, completion pending/failed, completed, and wrong/stale context.
- **Safety:** Changing patient/context requires explicit navigation and purges decrypted state. The banner never trusts route IDs alone; it derives from the authoritative active consultation projection.

## Screen 2 — Full medical record

**Maturity:** Planned, Phase 05. **Route concept:** `/consultations/:id/record`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity read-only medical-record screen with summary rail, paginated clinical timeline, and persistent active-consultation context.

- **Purpose:** Present the bounded longitudinal record permitted during the active consultation.
- **Layout:** Summary rail for demographics and active facts; main timeline with prior visits, diagnoses, prescriptions, labs/files, and provenance. Filters are bounded and pagination is explicit.
- **Content/actions:** Expand a record, open authorized file, jump to current encounter editor, and copy nothing automatically. Viewing itself is audited.
- **States:** Loading, partial/degraded section, empty section, denied before start/after end, access suspended, page error, and corrected/current marker.
- **Privacy/accessibility:** No arbitrary National ID search. Sensitive values remain on-screen only while access is valid. Semantic headings/timeline order, keyboard expansion, large text, and non-color provenance markers are required.

## Screen 3 — Encounter editor

**Maturity:** Planned, Phase 05. **Route concept:** `/consultations/:id/encounter`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity encounter editor with section navigation, structured form canvas, patient context banner, and persistent save/sync footer.

- **Purpose:** Capture the current visit in structured, independently saved sections.
- **Layout:** Section navigation for complaint, symptoms, examination, diagnosis, notes, allergies, chronic conditions, current medications, and follow-up; main form plus persistent save-state footer.
- **Content/actions:** Edit one section, Save now, add/revise structured facts, navigate without losing local draft, and open prescription/lab tools. Autosave is transparent, never silent about destination.
- **States:** Clean, unsaved, saving locally, queued, syncing, synced, validation error, conflict, access revoked, and completion locked.
- **Rules:** Each patch carries base version and one intent. No character-level auto-merge, no clinical write from AI, and no assertion that local save equals server save.

## Screen 4 — Offline drafts and conflict center

**Maturity:** Planned, Phase 05. **Route concept:** modal/drawer entered from sync banner.

**Stitch target:** Desktop — Electron doctor workspace overlay inside a 1440 × 900 px frame. Generate a large conflict-resolution drawer or modal over the clinical workspace, with local/server comparison and safe recovery actions.

- **Purpose:** Explain exactly what is local, pending, acknowledged, conflicted, failed, revoked, or expired, and let the assigned doctor recover safely.
- **Layout:** Summary banner, ordered operation list by section, local/server timestamps and versions, conflict comparison, and recovery actions.
- **Content/actions:** Retry same operation, refresh authorization, compare versions, explicitly choose/copy content into a new revision, or discard only after reasoned confirmation.
- **States:** Offline, reconnecting, syncing, ambiguous timeout, conflict, encounter completed elsewhere, access suspended/revoked, wrong/corrupt key, and retention expiry.
- **Safety:** Never sync to another patient/encounter, never create a blank DB over recoverable ciphertext, and never auto-merge free text. Decrypted content disappears on lock/inactivity.

## Screen 5 — Consultation completion review

**Maturity:** Planned, Phases 04–06. **Route concept:** guarded completion dialog/page.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate a guarded completion review page or large modal with grouped checklist, patient identity, blockers, warnings, and a separated final action.

- **Purpose:** Review required clinical decisions before one atomic End Consultation command.
- **Layout:** Checklist grouped by encounter sections, prescription disposition, labs/follow-up, unsynced work, and warnings; final summary with patient/context identity.
- **Content/actions:** Go to missing section, finalize or explicitly handle prescription draft per policy, retry sync, confirm End Consultation, or cancel.
- **States:** Ready, required item missing, warning only, offline prohibited, pending, unknown outcome/reconcile, failed with consultation still active, and completed.
- **Rules/accessibility:** Never claim completion before server confirmation. The final button has precise wording, remains keyboard accessible, and is disabled only with a visible explanation.

## Screen 6 — Own history and clinical correction

**Maturity:** Planned, Phase 05. **Route concept:** `/my-patients/:patient/own-history`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity own-history screen with restricted encounter timeline, immutable revisions, and a correction side panel.

- **Purpose:** After completion, let a doctor read only their own contributions and append a reasoned correction without reopening full cross-doctor history.
- **Layout:** Encounter list/timeline limited to the current doctor; detail panel shows immutable revisions, current marker, author/time, and correction history.
- **Content/actions:** Open contribution, start correction, enter mandatory reason and revised content, review diff, submit with expected version.
- **States:** No own history, current/corrected, stale version, denied, correction pending, and correction committed.
- **Safety:** Prior versions remain retrievable and never look deleted. The screen cannot navigate into other doctors' record content or infer it from missing data.

## Sources

Phases [04](../docs/phases/04_realtime_queue_and_consultation_control.md), [05](../docs/phases/05_clinical_records_encounters_and_local_resilience.md), and [06](../docs/phases/06_prescriptions_reminders_and_printing.md).
