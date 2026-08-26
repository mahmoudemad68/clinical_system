export const en = {
  app: {
    title: 'Clinic Admin',
    language: 'Language',
  },
  health: {
    title: 'Platform health',
    version: 'Version',
    serverTime: 'Server time',
    loading: 'Checking platform health…',
    unreachable: 'The platform could not be reached.',
    requestId: 'Request ID',
    components: {
      core: 'Core',
      realtime: 'Realtime',
      ai: 'AI',
    },
    status: {
      operational: 'Operational',
      degraded: 'Degraded',
      unavailable: 'Unavailable',
    },
  },
  auth: {
    title: 'Admin sign in',
    phone: 'Mobile number',
    password: 'Password',
    mfaCode: 'Authenticator code',
    signIn: 'Sign in',
    signOut: 'Sign out',
    failed: 'Sign-in could not be completed.',
  },
} as const;
