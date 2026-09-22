import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';

class PatientAuthPanel extends StatefulWidget {
  const PatientAuthPanel({
    super.key,
    required this.api,
    required this.onAuthenticated,
  });

  final AuthApi api;
  final VoidCallback onAuthenticated;

  @override
  State<PatientAuthPanel> createState() => _PatientAuthPanelState();
}

class _PatientAuthPanelState extends State<PatientAuthPanel> {
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _name = TextEditingController();
  final _nationalId = TextEditingController();
  final _code = TextEditingController();
  String? _challengeId;
  String? _message;
  bool _registering = false;
  bool _busy = false;

  void _clearNationalId() {
    _nationalId.clear();
  }

  @override
  void dispose() {
    _clearNationalId();
    _phone.dispose();
    _password.dispose();
    _name.dispose();
    _nationalId.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _message = null;
    });
    final strings = ClinicStrings.of(context);
    try {
      if (_challengeId != null) {
        await widget.api.verifyOtp(
          challengeId: _challengeId!,
          code: _code.text,
          platform: Theme.of(context).platform == TargetPlatform.iOS
              ? 'ios'
              : 'android',
          deviceLabel: 'patient-mobile',
          idempotencyKey:
              'otp-${DateTime.now().toUtc().millisecondsSinceEpoch}',
        );
        _clearNationalId();
        widget.onAuthenticated();
        return;
      }

      if (_registering) {
        final challenge = await widget.api.register(
          name: _name.text,
          phone: _phone.text,
          nationalId: _nationalId.text,
          password: _password.text,
          language: Localizations.localeOf(context).languageCode,
          idempotencyKey:
              'reg-${DateTime.now().toUtc().millisecondsSinceEpoch}',
        );
        _clearNationalId();
        setState(() => _challengeId = challenge.challengeId);
        return;
      }

      final outcome = await widget.api.login(
        phone: _phone.text,
        password: _password.text,
        platform: Theme.of(context).platform == TargetPlatform.iOS
            ? 'ios'
            : 'android',
        deviceLabel: 'patient-mobile',
      );
      _clearNationalId();
      if (outcome.mfaRequired) {
        setState(() => _challengeId = outcome.challengeId);
        return;
      }
      widget.onAuthenticated();
    } on ApiFailure catch (failure) {
      setState(() => _message = failure.redacting(_nationalId.text).message);
    } catch (_) {
      setState(() => _message = strings.authFailed);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_registering) ...[
          TextField(
            key: const Key('auth-name'),
            controller: _name,
            decoration: InputDecoration(labelText: strings.name),
          ),
          TextField(
            key: const Key('auth-national-id'),
            controller: _nationalId,
            decoration: InputDecoration(labelText: strings.nationalId),
            keyboardType: TextInputType.number,
            obscureText: true,
            enableSuggestions: false,
            autocorrect: false,
          ),
        ],
        TextField(
          key: const Key('auth-phone'),
          controller: _phone,
          decoration: InputDecoration(labelText: strings.phone),
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.username],
        ),
        TextField(
          key: const Key('auth-password'),
          controller: _password,
          decoration: InputDecoration(labelText: strings.password),
          obscureText: true,
          autofillHints: const [AutofillHints.password],
        ),
        if (_challengeId != null)
          TextField(
            key: const Key('auth-otp'),
            controller: _code,
            decoration: InputDecoration(labelText: strings.otpCode),
            keyboardType: TextInputType.number,
            autofillHints: const [AutofillHints.oneTimeCode],
          ),
        if (_message != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_message!, key: const Key('auth-message')),
          ),
        const SizedBox(height: 12),
        FilledButton(
          key: const Key('auth-submit'),
          onPressed: _busy ? null : _submit,
          child: Text(_registering ? strings.register : strings.signIn),
        ),
        TextButton(
          key: const Key('auth-toggle'),
          onPressed: _busy
              ? null
              : () => setState(() {
                  _registering = !_registering;
                  _challengeId = null;
                  _clearNationalId();
                }),
          child: Text(_registering ? strings.signIn : strings.register),
        ),
      ],
    );
  }
}
