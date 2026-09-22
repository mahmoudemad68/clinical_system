import 'package:clinic_design_system/clinic_design_system.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'shell/locale_controller.dart';
import 'shell/patient_shell.dart';

/// Clinic Patient client — Phase 02 profile onboarding and demographics.
void main() {
  runApp(const ProviderScope(child: ClinicApp()));
}

class ClinicApp extends ConsumerWidget {
  const ClinicApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);

    return MaterialApp(
      title: 'Clinic Patient',
      debugShowCheckedModeBanner: false,
      theme: ClinicTheme.light(),
      darkTheme: ClinicTheme.dark(),
      locale: locale,
      supportedLocales: ClinicLocales.supported,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: const PatientShell(),
    );
  }
}
