let locale = 'en';

export function setClientLocale(next: string): void {
  locale = next.startsWith('ar') ? 'ar' : 'en';
}

export function clientLocale(): string {
  return locale;
}
