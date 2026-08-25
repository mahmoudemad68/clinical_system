import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';

import 'status_indicator.dart';

/// The Phase 00 end-to-end surface, shared by all three Flutter clients.
///
/// Displays core health and version in Arabic and English. Written once here
/// rather than three times in three apps: three copies drift, and the Arabic
/// one drifts first because it is the one nobody re-reads.
class HealthPanel extends StatelessWidget {
  const HealthPanel({
    required this.health,
    required this.isLoading,
    this.errorMessage,
    this.requestId,
    this.onRetry,
    super.key,
  });

  final PlatformHealth? health;
  final bool isLoading;
  final String? errorMessage;
  final String? requestId;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(strings.healthTitle, style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
            ..._body(context, strings),
          ],
        ),
      ),
    );
  }

  List<Widget> _body(BuildContext context, ClinicStrings strings) {
    if (isLoading) {
      return [
        Semantics(
          label: strings.healthLoading,
          child: const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: LinearProgressIndicator(),
          ),
        ),
        const SizedBox(height: 8),
        Text(strings.healthLoading, key: const Key('health-loading')),
      ];
    }

    final current = health;

    if (current == null) {
      return [
        Text(
          errorMessage ?? strings.healthUnreachable,
          key: const Key('health-error'),
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
        if (requestId != null) ...[
          const SizedBox(height: 8),
          // Surfaced rather than hidden: it is the only handle support has to
          // find the server-side trace for this exact failure.
          SelectableText(
            '${strings.requestId}: $requestId',
            key: const Key('health-request-id'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        if (onRetry != null) ...[
          const SizedBox(height: 12),
          FilledButton(
            key: const Key('health-retry'),
            onPressed: onRetry,
            child: Text(strings.healthRetry),
          ),
        ],
      ];
    }

    return [
      Text(current.message, key: const Key('health-message')),
      const SizedBox(height: 12),
      StatusIndicator(label: strings.componentCore, status: current.core),
      StatusIndicator(
        label: strings.componentRealtime,
        status: current.realtime,
      ),
      StatusIndicator(label: strings.componentAi, status: current.ai),
      const Divider(height: 24),
      _labelled(
        context,
        strings.version,
        current.version,
        const Key('health-version'),
      ),
      _labelled(
        context,
        strings.serverTime,
        // Server sends UTC; display in the device's local time. Conversion at
        // the edge only, never persisted (plan.md section 106).
        current.serverTime.toLocal().toString(),
        const Key('health-server-time'),
      ),
    ];
  }

  Widget _labelled(BuildContext context, String label, String value, Key key) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(
            child: Text(label, style: Theme.of(context).textTheme.bodySmall),
          ),
          Text(value, key: key),
        ],
      ),
    );
  }
}
