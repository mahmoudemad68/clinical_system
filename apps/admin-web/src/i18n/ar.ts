export const ar = {
  app: {
    title: 'لوحة إدارة العيادة',
    language: 'اللغة',
  },
  health: {
    title: 'حالة المنصة',
    version: 'الإصدار',
    serverTime: 'توقيت الخادم',
    loading: 'جارٍ التحقق من حالة المنصة…',
    unreachable: 'تعذر الوصول إلى المنصة.',
    requestId: 'معرف الطلب',
    components: {
      core: 'الأساسي',
      realtime: 'الزمن الفعلي',
      ai: 'الذكاء الاصطناعي',
    },
    status: {
      operational: 'تعمل',
      degraded: 'مُتدهورة',
      unavailable: 'غير متاحة',
    },
  },
} as const;
