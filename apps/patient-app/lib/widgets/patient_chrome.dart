import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';

class LanguageMenuButton extends ConsumerWidget {
  const LanguageMenuButton({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final locale = ref.watch(localeProvider);
    return PopupMenuButton<Locale>(
      key: const Key('language-menu'),
      icon: const Icon(Icons.translate),
      tooltip: strings.language,
      initialValue: locale,
      onSelected: (value) => ref.read(localeProvider.notifier).select(value),
      itemBuilder: (context) => const [
        PopupMenuItem(value: ClinicLocales.english, child: Text('English')),
        PopupMenuItem(value: ClinicLocales.arabic, child: Text('العربية')),
      ],
    );
  }
}

class SignOutButton extends StatelessWidget {
  const SignOutButton({
    super.key,
    required this.onPressed,
    this.enabled = true,
  });

  final VoidCallback onPressed;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    return IconButton(
      key: const Key('sign-out'),
      tooltip: strings.signOut,
      onPressed: enabled ? onPressed : null,
      icon: const Icon(Icons.logout),
    );
  }
}

class PrimaryBottomBar extends StatelessWidget {
  const PrimaryBottomBar({
    super.key,
    required this.primaryLabel,
    required this.onPrimary,
    this.secondaryLabel,
    this.onSecondary,
    this.busy = false,
  });

  final String primaryLabel;
  final VoidCallback? onPrimary;
  final String? secondaryLabel;
  final VoidCallback? onSecondary;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            FilledButton(
              key: const Key('primary-action'),
              onPressed: busy ? null : onPrimary,
              child: busy
                  ? const SizedBox(
                      height: 22,
                      width: 22,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(primaryLabel),
            ),
            if (secondaryLabel != null) ...[
              const SizedBox(height: 8),
              TextButton(
                key: const Key('secondary-action'),
                onPressed: busy ? null : onSecondary,
                child: Text(secondaryLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class FieldErrorText extends StatelessWidget {
  const FieldErrorText(this.message, {super.key});

  final String? message;

  @override
  Widget build(BuildContext context) {
    if (message == null) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Semantics(
        liveRegion: true,
        child: Text(
          message!,
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
      ),
    );
  }
}
