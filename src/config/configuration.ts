export default () => ({
  app: {
    name: process.env.APP_NAME ?? 'KarnaCab API',
    env: process.env.NODE_ENV ?? 'development',
    port: parseInt(process.env.PORT || process.env.APP_PORT || '3000', 10),
    url: process.env.APP_URL ?? 'http://localhost:3000',
    prefix: process.env.API_PREFIX ?? 'api',
    version: process.env.API_VERSION ?? '1',
  },
  database: {
    url: process.env.DATABASE_URL,
    host: process.env.DB_HOST ?? 'localhost',
    port: parseInt(process.env.DB_PORT ?? '3306', 10),
    name: process.env.DB_NAME ?? 'karnacab_platform',
    user: process.env.DB_USER ?? 'root',
    password: process.env.DB_PASSWORD ?? '',
  },
  redis: {
    host: process.env.REDIS_HOST ?? '127.0.0.1',
    port: parseInt(process.env.REDIS_PORT ?? '6379', 10),
    password: process.env.REDIS_PASSWORD || undefined,
    db: parseInt(process.env.REDIS_DB ?? '0', 10),
  },
  cors: {
    origins: (process.env.CORS_ORIGINS ?? 'http://localhost:8000,http://localhost:8001')
      .split(',')
      .map((origin) => origin.trim())
      .filter(Boolean),
  },
  log: {
    level: process.env.LOG_LEVEL ?? 'info',
  },
  jwt: {
    secret: process.env.JWT_SECRET ?? 'karnacab-dev-jwt',
  },
  kyc: {
    storageDir: process.env.KYC_STORAGE_DIR ?? 'storage/kyc',
    maxBytes: parseInt(process.env.KYC_MAX_BYTES ?? String(8 * 1024 * 1024), 10),
    cloudinaryUrl: process.env.CLOUDINARY_URL ?? '',
    cloudName: process.env.CLOUDINARY_CLOUD_NAME ?? 'fjq1hs8g',
  },
  demo: {
    autoAssign: (process.env.DEMO_AUTO_ASSIGN ?? 'false').toLowerCase() === 'true',
  },
  google: {
    mapsApiKey: process.env.GOOGLE_MAPS_API ?? process.env.GOOGLE_MAPS_API_KEY ?? '',
  },
  payments: {
    gateway: process.env.PAYMENT_GATEWAY ?? 'demo',
    webhookSecret: process.env.PAYMENT_WEBHOOK_SECRET ?? process.env.JWT_SECRET ?? 'karnacab-dev-jwt',
  },
  ads: {
    storageDir: process.env.ADS_STORAGE_DIR ?? 'storage/ads',
  },
  support: {
    storageDir: process.env.SUPPORT_STORAGE_DIR ?? 'storage/support',
  },
  firebase: {
    projectId: process.env.FIREBASE_PROJECT_ID ?? 'karnacab-bf930',
    projectNumber: process.env.FIREBASE_PROJECT_NUMBER ?? '924689551761',
    serviceAccountJson: process.env.FIREBASE_SERVICE_ACCOUNT_JSON ?? '',
    serviceAccountPath: process.env.FIREBASE_SERVICE_ACCOUNT_PATH ?? 'firebase-service-account.json',
  },
});
