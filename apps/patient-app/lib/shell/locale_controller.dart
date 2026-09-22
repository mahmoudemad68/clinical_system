import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class LocaleController extends Notifier<Locale> {
  @override
  Locale build() => ClinicLocales.english;

  void select(Locale locale) {
    state = ClinicLocales.resolve(locale);
  }
}

final localeProvider = NotifierProvider<LocaleController, Locale>(
  LocaleController.new,
);
