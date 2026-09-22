import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../session/session_controller.dart';
import 'locale_controller.dart';

class PatientChrome extends ConsumerWidget {
  const PatientChrome({
    required this.title,
    required this.body,
    this.footer,
    this.showSignOut = false,
    this.actions = const [],
    super.key,
  });

  final String title;
  final Widget body;
  final Widget? footer;
  final bool showSignOut;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final locale = ref.watch(localeProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          ...actions,
          PopupMenuButton<Locale>(
            key: const Key('language-menu'),
            icon: const Icon(Icons.translate),
            tooltip: strings.language,
            initialValue: locale,
            onSelected: (value) =>
                ref.read(localeProvider.notifier).select(value),
            itemBuilder: (context) => const [
              PopupMenuItem(
                value: ClinicLocales.english,
                child: Text('English'),
              ),
              PopupMenuItem(
                value: ClinicLocales.arabic,
                child: Text('العربية'),
              ),
            ],
          ),
          if (showSignOut)
            IconButton(
              key: const Key('sign-out'),
              tooltip: strings.signOut,
              onPressed: () => ref.read(sessionProvider.notifier).signOut(),
              icon: const Icon(Icons.logout),
            ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: body,
          ),
        ),
      ),
      bottomNavigationBar: footer == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                child: footer,
              ),
            ),
    );
  }
}
