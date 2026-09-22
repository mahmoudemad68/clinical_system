import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../session/patient_session.dart';
import '../widgets/patient_chrome.dart';
import 'edit_demographics_screen.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key, required this.profile});

  final PatientProfile profile;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(strings.profileTitle),
        actions: [
          const LanguageMenuButton(),
          SignOutButton(
            onPressed: () =>
                ref.read(patientSessionProvider.notifier).signOut(),
          ),
        ],
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (!profile.isEditable)
              Card(
                key: const Key('profile-readonly'),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(strings.profileReadOnly),
                ),
              ),
            ListTile(
              title: Text(strings.fullName),
              subtitle: Text(
                profile.fullName,
                key: const Key('profile-full-name'),
              ),
            ),
            ListTile(
              title: Text(strings.profileStatus),
              subtitle: Text(
                strings.profileStatusLabel(profile.status.wire),
                key: const Key('profile-status'),
              ),
            ),
            ListTile(
              title: Text(strings.gender),
              subtitle: Text(strings.genderLabel(profile.gender)),
            ),
            ListTile(
              title: Text(strings.dateOfBirth),
              subtitle: Text(profile.dateOfBirth ?? strings.notProvided),
            ),
            ListTile(
              title: Text(strings.maritalStatus),
              subtitle: Text(strings.maritalLabel(profile.maritalStatus)),
            ),
            ListTile(
              title: Text('${strings.heightCm} (${strings.selfReportedLabel})'),
              subtitle: Text(profile.heightCm ?? strings.notProvided),
            ),
            ListTile(
              title: Text('${strings.weightKg} (${strings.selfReportedLabel})'),
              subtitle: Text(profile.weightKg ?? strings.notProvided),
            ),
            ListTile(
              title: Text(
                '${strings.bloodType} (${strings.selfReportedLabel})',
              ),
              subtitle: Text(profile.bloodType ?? strings.notProvided),
            ),
            ListTile(
              title: Text(strings.selfReportedLabel),
              subtitle: Text(strings.selfReportedHint),
            ),
            ListTile(
              title: Text(strings.profileReference),
              subtitle: Text(
                profile.patientId,
                key: const Key('profile-reference'),
              ),
            ),
            ListTile(
              title: Text(strings.lastUpdated),
              subtitle: Text(profile.updatedAt.toLocal().toString()),
            ),
            const SizedBox(height: 16),
            if (profile.isEditable)
              FilledButton(
                key: const Key('profile-edit'),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      settings: const RouteSettings(name: '/profile/edit'),
                      builder: (_) => EditDemographicsScreen(profile: profile),
                    ),
                  );
                },
                child: Text(strings.editDemographics),
              ),
            TextButton(
              key: const Key('profile-refresh'),
              onPressed: () =>
                  ref.read(patientSessionProvider.notifier).refresh(),
              child: Text(strings.refreshAction),
            ),
          ],
        ),
      ),
    );
  }
}
