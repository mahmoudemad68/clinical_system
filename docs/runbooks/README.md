# Runbooks

One file per alert. Every alert rule in `infra/monitoring/alerts/` links here,
because an alert without instructions trains people to ignore alerts.

Each runbook answers four questions in order: what fired, what is the user
impact, how to confirm, and what to do. Diagnosis before action — a runbook that
opens with "restart the service" teaches people to restart before understanding.

| Runbook | Alert | Severity |
| --- | --- | --- |
| [core-api-unavailable](core-api-unavailable.md) | `CoreApiDown`, `CoreApiNotReady` | critical |
| [ai-service-degraded](ai-service-degraded.md) | `AiServiceDegraded` | warning |
| [outbox-backlog](outbox-backlog.md) | `OutboxBacklogGrowing`, `OutboxOldestEventStale` | warning |
| [outbox-dead-letter](outbox-dead-letter.md) | `OutboxDeadLetterPresent` | critical |
| [database-connections](database-connections.md) | `DatabaseConnectionsNearLimit` | warning |
| [slow-queries](slow-queries.md) | `SlowQueriesRising` | warning |
| [redaction-failure](redaction-failure.md) | `RedactionCanaryDetected` | critical |
| [authorization-denials](authorization-denials.md) | `AuthorizationDenialSpike` | warning |
| [emergency-credential-rotation](emergency-credential-rotation.md) | Manual / security incident | critical |
| [sms-outage](sms-outage.md) | `OtpQueueSpike`, `OtpDeliveryAgeHigh` | warning |
| [account-takeover](account-takeover.md) | `PrivilegedMfaBypassAttempt` | critical |
| [lost-device](lost-device.md) | Manual | warning |
| [mfa-recovery](mfa-recovery.md) | Manual | warning |
| [mass-revocation](mass-revocation.md) | Incident | critical |
| [identity-key-rotation](identity-key-rotation.md) | Configuration / `/ready` | critical |
| [disputed-profile-link](disputed-profile-link.md) | Manual; claim flag off | warning |
| [refresh-reuse](refresh-reuse.md) | `RefreshTokenReuse` | critical |

## Standing rules

- **Never paste patient data into a ticket, chat, or incident channel.** Use the
  correlation ID. Every log line and outbox row carries one.
- **Never disable redaction to debug.** If you cannot diagnose without seeing
  the value, that is a finding about diagnosability, not a reason to leak.
- **Dead-letter rows are evidence.** Do not prune them to clear an alert.
- A runbook that turned out to be wrong during an incident gets fixed in the
  same week, while the memory is fresh.
