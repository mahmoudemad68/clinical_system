import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../onboarding/demographic_validation.dart';
import '../shell/patient_chrome.dart';
import 'demographics_edit_controller.dart';
import 'own_profile_controller.dart';
import 'patient_surface.dart';

class EditDemographicsScreen extends ConsumerStatefulWidget {
  const EditDemographicsScreen({super.key});

  @override
  ConsumerState<EditDemographicsScreen> createState() =>
      _EditDemographicsScreenState();
}

class _EditDemographicsScreenState
    extends ConsumerState<EditDemographicsScreen> {
  late final TextEditingController _fullName;
  late final TextEditingController _dateOfBirth;
  late final TextEditingController _height;
  late final TextEditingController _weight;
  String _gender = '';
  String _marital = '';
  String _blood = '';
  String? _validation;
  bool _hydrated = false;

  @override
  void initState() {
    super.initState();
    _fullName = TextEditingController();
    _dateOfBirth = TextEditingController();
    _height = TextEditingController();
    _weight = TextEditingController();
  }

  @override
  void dispose() {
    _fullName.dispose();
    _dateOfBirth.dispose();
    _height.dispose();
    _weight.dispose();
    super.dispose();
  }

  void _hydrate(PatientProfile profile) {
    if (_hydrated) {
      return;
    }
    _hydrated = true;
    _fullName.text = profile.fullName;
    _dateOfBirth.text = profile.dateOfBirth ?? '';
    _height.text = profile.heightCm ?? '';
    _weight.text = profile.weightKg ?? '';
    _gender = profile.gender;
    _marital = profile.maritalStatus ?? '';
    _blood = profile.bloodType ?? '';
  }

  void _applyLatest(PatientProfile profile) {
    _fullName.text = profile.fullName;
    _dateOfBirth.text = profile.dateOfBirth ?? '';
    _height.text = profile.heightCm ?? '';
    _weight.text = profile.weightKg ?? '';
    setState(() {
      _gender = profile.gender;
      _marital = profile.maritalStatus ?? '';
      _blood = profile.bloodType ?? '';
      _validation = null;
    });
    ref.read(demographicsEditProvider.notifier).reset();
  }

  Future<void> _save(PatientProfile profile, ClinicStrings strings) async {
    final name = DemographicValidation.fullName(_fullName.text);
    final gender = DemographicValidation.gender(_gender);
    final dob = DemographicValidation.dateOfBirth(
      _dateOfBirth.text,
      nowUtc: DateTime.now().toUtc(),
    );
    final height = DemographicValidation.heightCm(_height.text);
    final weight = DemographicValidation.weightKg(_weight.text);
    final marital = DemographicValidation.maritalStatus(_marital);
    final blood = DemographicValidation.bloodType(_blood);
    if (name != null ||
        gender != null ||
        dob != null ||
        height != null ||
        weight != null ||
        marital != null ||
        blood != null) {
      setState(() {
        if (height != null) {
          _validation = strings.validationHeight;
        } else if (weight != null) {
          _validation = strings.validationWeight;
        } else if (dob != null) {
          _validation = strings.validationDate;
        } else if (name != null) {
          _validation = strings.validationFullName;
        } else {
          _validation = strings.validationEnum;
        }
      });
      return;
    }

    final patch = PatientDemographicsPatch(
      version: profile.version,
      fullName: _fullName.text.trim(),
      gender: _gender,
      dateOfBirth: _dateOfBirth.text.trim().isEmpty
          ? null
          : _dateOfBirth.text.trim(),
      clearDateOfBirth: _dateOfBirth.text.trim().isEmpty,
      heightCm: DemographicValidation.parseOptionalNumber(_height.text),
      clearHeightCm: _height.text.trim().isEmpty,
      weightKg: DemographicValidation.parseOptionalNumber(_weight.text),
      clearWeightKg: _weight.text.trim().isEmpty,
      maritalStatus: _marital.isEmpty ? null : _marital,
      clearMaritalStatus: _marital.isEmpty,
      bloodType: _blood.isEmpty ? null : _blood,
      clearBloodType: _blood.isEmpty,
    );

    final saved = await ref.read(demographicsEditProvider.notifier).save(patch);
    if (saved != null && mounted) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    final lookup = ref.watch(ownProfileProvider).asData?.value;
    final profile = currentProfileOf(lookup);
    final edit = ref.watch(demographicsEditProvider);

    if (profile == null) {
      return PatientChrome(
        title: strings.editDemographics,
        body: Center(child: Text(strings.loading)),
      );
    }
    _hydrate(profile);

    return PatientChrome(
      title: strings.editDemographics,
      footer: FilledButton(
        key: const Key('demographics-save'),
        onPressed: edit.busy || edit.conflict
            ? null
            : () => _save(profile, strings),
        child: edit.busy
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Text(strings.saveAction),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (edit.conflict) ...[
            Card(
              key: const Key('version-conflict'),
              color: Theme.of(context).colorScheme.errorContainer,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      strings.versionConflictTitle,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 8),
                    Text(strings.versionConflictBody),
                    if (edit.requestId != null) ...[
                      const SizedBox(height: 8),
                      Text('${strings.requestId}: ${edit.requestId}'),
                    ],
                    const SizedBox(height: 12),
                    FilledButton(
                      key: const Key('version-conflict-refresh'),
                      onPressed: () async {
                        await ref.read(ownProfileProvider.notifier).refresh();
                        final latest = currentProfileOf(
                          ref.read(ownProfileProvider).asData?.value,
                        );
                        if (latest != null) {
                          _applyLatest(latest);
                        }
                      },
                      child: Text(strings.versionConflictRefresh),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
          ],
          TextField(
            key: const Key('edit-full-name'),
            controller: _fullName,
            enabled: !edit.conflict,
            decoration: InputDecoration(labelText: strings.fieldFullName),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('edit-gender'),
            // ignore: deprecated_member_use
            value: _gender.isEmpty ? null : _gender,
            decoration: InputDecoration(labelText: strings.fieldGender),
            items: [
              DropdownMenuItem(value: 'male', child: Text(strings.genderMale)),
              DropdownMenuItem(
                value: 'female',
                child: Text(strings.genderFemale),
              ),
            ],
            onChanged: edit.conflict
                ? null
                : (value) => setState(() => _gender = value ?? ''),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('edit-dob'),
            controller: _dateOfBirth,
            enabled: !edit.conflict,
            keyboardType: TextInputType.datetime,
            decoration: InputDecoration(
              labelText: strings.fieldDateOfBirth,
              hintText: 'YYYY-MM-DD',
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('edit-marital'),
            // ignore: deprecated_member_use
            value: _marital.isEmpty ? null : _marital,
            decoration: InputDecoration(labelText: strings.fieldMaritalStatus),
            items: [
              DropdownMenuItem(
                value: 'single',
                child: Text(strings.maritalSingle),
              ),
              DropdownMenuItem(
                value: 'married',
                child: Text(strings.maritalMarried),
              ),
              DropdownMenuItem(
                value: 'divorced',
                child: Text(strings.maritalDivorced),
              ),
              DropdownMenuItem(
                value: 'widowed',
                child: Text(strings.maritalWidowed),
              ),
            ],
            onChanged: edit.conflict
                ? null
                : (value) => setState(() => _marital = value ?? ''),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('edit-height'),
            controller: _height,
            enabled: !edit.conflict,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: '${strings.fieldHeight} (${strings.selfReported})',
              helperText: strings.boundsHint,
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('edit-weight'),
            controller: _weight,
            enabled: !edit.conflict,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: '${strings.fieldWeight} (${strings.selfReported})',
              helperText: strings.selfReportedHint,
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('edit-blood-type'),
            // ignore: deprecated_member_use
            value: _blood.isEmpty ? null : _blood,
            decoration: InputDecoration(
              labelText: '${strings.fieldBloodType} (${strings.selfReported})',
              helperText: strings.bloodTypeSelfReportedHint,
            ),
            items: [
              for (final type in PatientBloodType.supported)
                DropdownMenuItem(value: type.wire, child: Text(type.wire)),
            ],
            onChanged: edit.conflict
                ? null
                : (value) => setState(() => _blood = value ?? ''),
          ),
          if (_validation != null) ...[
            const SizedBox(height: 12),
            Text(
              _validation!,
              key: const Key('edit-validation-error'),
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          if (edit.error != null && !edit.conflict) ...[
            const SizedBox(height: 12),
            Text(
              edit.error!,
              key: const Key('edit-error'),
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}
