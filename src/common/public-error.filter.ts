import { Catch, ExceptionFilter, HttpException, HttpStatus, Logger } from '@nestjs/common';
import { ArgumentsHost } from '@nestjs/common';
import type { FastifyReply } from 'fastify';

const PUBLIC_500 = 'Something went wrong. Please try again.';

@Catch()
export class PublicErrorFilter implements ExceptionFilter {
  private readonly logger = new Logger(PublicErrorFilter.name);

  catch(exception: unknown, host: ArgumentsHost) {
    const reply = host.switchToHttp().getResponse<FastifyReply>();
    if (exception instanceof HttpException) {
      const status = exception.getStatus();
      const body = exception.getResponse();
      if (status >= 500) {
        this.logger.error({ err: exception, status }, 'HttpException 5xx hidden from client');
        return reply.status(status).send({ statusCode: status, message: PUBLIC_500 });
      }
      if (typeof body === 'string') {
        return reply.status(status).send({ statusCode: status, message: body });
      }
      return reply.status(status).send(body);
    }
    this.logger.error({ err: exception }, 'Unhandled error');
    return reply.status(HttpStatus.INTERNAL_SERVER_ERROR).send({
      statusCode: HttpStatus.INTERNAL_SERVER_ERROR,
      message: PUBLIC_500,
    });
  }
}

export function publicErrorMessage(status: number, raw?: string) {
  if (status >= 500) {
    return PUBLIC_500;
  }
  return raw ?? PUBLIC_500;
}
