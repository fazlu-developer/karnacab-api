import { plainToInstance, Transform } from 'class-transformer';
import {
  IsEnum,
  IsInt,
  IsOptional,
  IsString,
  Max,
  Min,
  validateSync,
} from 'class-validator';

enum Environment {
  Development = 'development',
  Test = 'test',
  Production = 'production',
}

const toInt = ({ value }: { value: unknown }) => {
  if (value === undefined || value === null || value === '') {
    return undefined;
  }
  return parseInt(String(value), 10);
};

class EnvironmentVariables {
  @IsEnum(Environment)
  @IsOptional()
  NODE_ENV?: Environment = Environment.Development;

  @Transform(toInt)
  @IsInt()
  @Min(1)
  @Max(65535)
  @IsOptional()
  APP_PORT?: number = 3000;

  @IsString()
  @IsOptional()
  APP_NAME?: string = 'KarnaCab API';

  @IsString()
  @IsOptional()
  API_PREFIX?: string = 'api';

  @IsString()
  DATABASE_URL!: string;

  @IsString()
  @IsOptional()
  REDIS_HOST?: string = '127.0.0.1';

  @Transform(toInt)
  @IsInt()
  @Min(1)
  @Max(65535)
  @IsOptional()
  REDIS_PORT?: number = 6379;

  @IsString()
  @IsOptional()
  DEMO_AUTO_ASSIGN?: string;

  @IsString()
  @IsOptional()
  GOOGLE_MAPS_API?: string;

  @IsString()
  @IsOptional()
  GOOGLE_MAPS_API_KEY?: string;

  @IsString()
  @IsOptional()
  PAYMENT_WEBHOOK_SECRET?: string;

  @IsString()
  @IsOptional()
  PAYMENT_GATEWAY?: string;

  @IsString()
  @IsOptional()
  FIREBASE_PROJECT_ID?: string;

  @IsString()
  @IsOptional()
  FIREBASE_SERVICE_ACCOUNT_JSON?: string;

  @IsString()
  @IsOptional()
  CLOUDINARY_URL?: string;

  @IsString()
  @IsOptional()
  CLOUDINARY_CLOUD_NAME?: string;
}

export function validateEnv(config: Record<string, unknown>) {
  const validated = plainToInstance(EnvironmentVariables, config, {
    enableImplicitConversion: true,
  });

  const errors = validateSync(validated, {
    skipMissingProperties: false,
  });

  if (errors.length > 0) {
    throw new Error(errors.toString());
  }

  return validated;
}
