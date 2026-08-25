import 'package:flutter/material.dart';

/// Shared theme.
///
/// One seed colour and Material 3, so the three clients look like one product.
/// Contrast is left to Material's generated scheme rather than hand-picked
/// colours: hand-picked pairs are where accessibility regressions come from.
class ClinicTheme {
  const ClinicTheme._();

  static const Color _seed = Color(0xFF00696D);

  static ThemeData light() => ThemeData(
    colorScheme: ColorScheme.fromSeed(seedColor: _seed),
    useMaterial3: true,
    visualDensity: VisualDensity.adaptivePlatformDensity,
  );

  static ThemeData dark() => ThemeData(
    colorScheme: ColorScheme.fromSeed(
      seedColor: _seed,
      brightness: Brightness.dark,
    ),
    useMaterial3: true,
    visualDensity: VisualDensity.adaptivePlatformDensity,
  );
}
