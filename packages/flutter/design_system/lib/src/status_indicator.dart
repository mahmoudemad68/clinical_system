import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';

/// Shows one component's status.
///
/// Status is conveyed by text *and* an icon *and* colour, never colour alone.
/// Colour-only status fails WCAG 1.4.1 and is unreadable for a colour-blind
/// pharmacist looking at a stock alert, which is the same widget in a later
/// phase.
class StatusIndicator extends StatelessWidget {
  const StatusIndicator({required this.label, required this.status, super.key});

  final String label;
  final ComponentStatus status;

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    final scheme = Theme.of(context).colorScheme;

    final (IconData icon, Color colour, String text) = switch (status) {
      ComponentStatus.operational => (
        Icons.check_circle_outline,
        scheme.primary,
        strings.statusOperational,
      ),
      ComponentStatus.degraded => (
        Icons.error_outline,
        scheme.tertiary,
        strings.statusDegraded,
      ),
      ComponentStatus.unavailable => (
        Icons.cancel_outlined,
        scheme.error,
        strings.statusUnavailable,
      ),
    };

    return Semantics(
      // One label for a screen reader rather than three separate nodes, so it
      // announces "Core, Operational" instead of stray fragments.
      label: '$label, $text',
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Icon(icon, color: colour, size: 20),
            const SizedBox(width: 8),
            Expanded(child: Text(label)),
            Text(text, style: TextStyle(color: colour)),
          ],
        ),
      ),
    );
  }
}
