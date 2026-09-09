# KarnaCab API

NestJS + Fastify foundation for KarnaCab. All clients talk to this service at `/api/v1`.

## Run

```bash
cp .env.example .env
npm install
npx prisma generate
npm run start:dev
```

- API: http://localhost:3000/api/v1
- Health: http://localhost:3000/api/v1/health
- Swagger: http://localhost:3000/api/docs
"# karnacab-api" 
