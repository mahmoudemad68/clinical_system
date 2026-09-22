import 'package:clinic_design_system/clinic_design_system.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app_providers.dart';
import '../shell/patient_chrome.dart';
import 'edit_demographics_screen.dart';
import 'own_profile_controller.dart';
import 'patient_surface.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final lookup = ref.watch(ownProfileProvider).asData?.value;
    final profile = currentProfileOf(lookup);
    if (profile == null) {
      return PatientChrome(
        title: strings.profileTitle,
        showSignOut: true,
        body: Center(
          child: Text(strings.loading, key: const Key('profile-loading')),
        ),
      );
    }

    final theme = Theme.of(context);
    return PatientChrome(
      title: strings.profileTitle,
      showSignOut: true,
      actions: [
        IconButton(
          key: const Key('profile-refresh'),
          tooltip: strings.refreshAction,
          onPressed: () => ref.read(ownProfileProvider.notifier).refresh(),
          icon: const Icon(Icons.refresh),
        ),
      ],
      footer: profile.canEditDemographics
          ? FilledButton(
              key: const Key('edit-demographics'),
              onPressed: () {
                Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => const EditDemographicsScreen(),
                  ),
                );
              },
              child: Text(strings.editDemographics),
            )
          : null,
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            profile.fullName,
            key: const Key('profile-full-name'),
            style: theme.textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          Text(
            '${strings.profileStatus}: ${strings.profileStatusLabel(profile.status.wire)}',
            key: const Key('profile-status'),
          ),
          Text(
            '${strings.profileVersion}: ${profile.version}',
            key: const Key('profile-version'),
          ),
          const SizedBox(height: 16),
          if (!profile.canEditDemographics)
            Text(
              strings.profileReadOnly,
              key: const Key('profile-readonly'),
              style: theme.textTheme.bodyMedium,
            ),
          const SizedBox(height: 8),
          _row(strings.fieldGender, strings.genderLabel(profile.gender)),
          _row(
            strings.fieldDateOfBirth,
            profile.dateOfBirth?.isNotEmpty == true
                ? profile.dateOfBirth!
                : strings.notProvided,
          ),
          _row(
            strings.fieldMaritalStatus,
            strings.maritalLabel(profile.maritalStatus),
          ),
          _row(
            '${strings.fieldHeight} (${strings.selfReported})',
            profile.heightCm ?? strings.notProvided,
          ),
          _row(
            '${strings.fieldWeight} (${strings.selfReported})',
            profile.weightKg ?? strings.notProvided,
          ),
          _row(
            '${strings.fieldBloodType} (${strings.selfReported})',
            profile.bloodType ?? strings.notProvided,
          ),
          const SizedBox(height: 8),
          Text(
            strings.bloodTypeSelfReportedHint,
            key: const Key('profile-blood-type-hint'),
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 24),
          _PlatformHealthCard(),
        ],
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _PlatformHealthCard extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final health = ref.watch(healthProvider);
    return switch (health) {
      AsyncData(:final value) => HealthPanel(health: value, isLoading: false),
      AsyncError(:final error) => HealthPanel(
        health: null,
        isLoading: false,
        errorMessage: error is ApiFailure
            ? error.message
            : strings.healthUnreachable,
        requestId: error is ApiFailure ? error.requestId : null,
        onRetry: () => ref.invalidate(healthProvider),
      ),
      _ => const HealthPanel(health: null, isLoading: true),
    };
  }
}
