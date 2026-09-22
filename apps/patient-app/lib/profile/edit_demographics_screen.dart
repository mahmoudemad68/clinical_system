import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../onboarding/onboarding_draft.dart';
import '../widgets/patient_chrome.dart';
import 'edit_demographics_controller.dart';

class EditDemographicsScreen extends ConsumerStatefulWidget {
  const EditDemographicsScreen({super.key, required this.profile});

  final PatientProfile profile;

  @override
  ConsumerState<EditDemographicsScreen> createState() =>
      _EditDemographicsScreenState();
}

class _EditDemographicsScreenState
    extends ConsumerState<EditDemographicsScreen> {
  late final TextEditingController _fullName;
  late final TextEditingController _height;
  late final TextEditingController _weight;

  @override
  void initState() {
    super.initState();
    _fullName = TextEditingController(text: widget.profile.fullName);
    _height = TextEditingController(text: widget.profile.heightCm ?? '');
    _weight = TextEditingController(text: widget.profile.weightKg ?? '');
    _fullName.addListener(_syncName);
    _height.addListener(_syncHeight);
    _weight.addListener(_syncWeight);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) {
        return;
      }
      ref.read(editDemographicsProvider.notifier).load(widget.profile);
    });
  }

  void _syncName() {
    ref.read(editDemographicsProvider.notifier).setFullName(_fullName.text);
  }

  void _syncHeight() {
    ref.read(editDemographicsProvider.notifier).setHeight(_height.text);
  }

  void _syncWeight() {
    ref.read(editDemographicsProvider.notifier).setWeight(_weight.text);
  }

  void _applyControllers(EditDemographicsViewState next) {
    if (_fullName.text != next.fullName) {
      _fullName.value = TextEditingValue(
        text: next.fullName,
        selection: TextSelection.collapsed(offset: next.fullName.length),
      );
    }
    if (_height.text != next.heightCm) {
      _height.value = TextEditingValue(
        text: next.heightCm,
        selection: TextSelection.collapsed(offset: next.heightCm.length),
      );
    }
    if (_weight.text != next.weightKg) {
      _weight.value = TextEditingValue(
        text: next.weightKg,
        selection: TextSelection.collapsed(offset: next.weightKg.length),
      );
    }
  }

  @override
  void dispose() {
    _fullName
      ..removeListener(_syncName)
      ..dispose();
    _height
      ..removeListener(_syncHeight)
      ..dispose();
    _weight
      ..removeListener(_syncWeight)
      ..dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    final current = ref.watch(editDemographicsProvider);
    final editor = current.loaded
        ? current
        : EditDemographicsViewState.fromProfile(widget.profile);
    ref.listen(editDemographicsProvider, (previous, next) {
      _applyControllers(next);
      if (next.saved &&
          previous?.saved != true &&
          mounted &&
          Navigator.of(context).canPop()) {
        Navigator.of(context).pop();
      }
    });
    final conflict = editor.conflict;
    final busy = editor.busy;

    return Scaffold(
      appBar: AppBar(
        title: Text(strings.editDemographicsTitle),
        actions: const [LanguageMenuButton()],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (conflict)
                Card(
                  key: const Key('version-conflict'),
                  color: Theme.of(context).colorScheme.errorContainer,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          strings.versionConflictTitle,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: 8),
                        Text(strings.versionConflictBody),
                      ],
                    ),
                  ),
                ),
              TextField(
                key: const Key('edit-full-name'),
                controller: _fullName,
                enabled: !conflict,
                decoration: InputDecoration(
                  labelText: strings.fullName,
                  errorText: editor.errors['full_name'],
                ),
              ),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(
                    value: 'female',
                    label: Text(strings.genderFemale),
                  ),
                  ButtonSegment(value: 'male', label: Text(strings.genderMale)),
                ],
                selected: {?editor.gender},
                onSelectionChanged: conflict
                    ? null
                    : (value) => ref
                          .read(editDemographicsProvider.notifier)
                          .setGender(value.first),
              ),
              FieldErrorText(editor.errors['gender']),
              ListTile(
                contentPadding: EdgeInsets.zero,
                enabled: !conflict,
                title: Text(strings.dateOfBirth),
                subtitle: Text(
                  editor.dateOfBirth == null
                      ? strings.optionalField
                      : isoDate(editor.dateOfBirth!),
                ),
                onTap: conflict
                    ? null
                    : () async {
                        final now = DateTime.now();
                        final picked = await showDatePicker(
                          context: context,
                          initialDate:
                              editor.dateOfBirth ?? DateTime(1990, 1, 15),
                          firstDate: DateTime(1850, 1, 1),
                          lastDate: DateTime(now.year, now.month, now.day),
                        );
                        if (picked != null) {
                          ref
                              .read(editDemographicsProvider.notifier)
                              .setDateOfBirth(picked);
                        }
                      },
              ),
              DropdownButtonFormField<String>(
                key: ValueKey('marital-${editor.maritalStatus}'),
                initialValue: editor.maritalStatus,
                decoration: InputDecoration(labelText: strings.maritalStatus),
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
                onChanged: conflict
                    ? null
                    : (value) => ref
                          .read(editDemographicsProvider.notifier)
                          .setMaritalStatus(value),
              ),
              const SizedBox(height: 12),
              Text(strings.selfReportedHint),
              const SizedBox(height: 8),
              TextField(
                key: const Key('edit-height'),
                controller: _height,
                enabled: !conflict,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText:
                      '${strings.heightCm} (${strings.selfReportedLabel})',
                  errorText: editor.errors['height_cm'],
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('edit-weight'),
                controller: _weight,
                enabled: !conflict,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText:
                      '${strings.weightKg} (${strings.selfReportedLabel})',
                  errorText: editor.errors['weight_kg'],
                ),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                key: ValueKey('blood-${editor.bloodType}'),
                initialValue: editor.bloodType,
                decoration: InputDecoration(
                  labelText:
                      '${strings.bloodType} (${strings.selfReportedLabel})',
                ),
                items: const ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
                    .map((v) => DropdownMenuItem(value: v, child: Text(v)))
                    .toList(),
                onChanged: conflict
                    ? null
                    : (value) => ref
                          .read(editDemographicsProvider.notifier)
                          .setBloodType(value),
              ),
              if (editor.failure != null && !conflict) ...[
                const SizedBox(height: 12),
                Text(
                  editor.failure!.message,
                  key: const Key('edit-error'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: 88),
            ],
          ),
        ),
      ),
      bottomNavigationBar: PrimaryBottomBar(
        primaryLabel: conflict ? strings.refreshAction : strings.saveAction,
        onPrimary: busy
            ? null
            : () {
                final notifier = ref.read(editDemographicsProvider.notifier);
                if (conflict) {
                  notifier.refresh();
                  return;
                }
                notifier.save(strings);
              },
        secondaryLabel: strings.cancelAction,
        onSecondary: busy ? null : () => Navigator.of(context).pop(),
        busy: busy,
      ),
    );
  }
}
