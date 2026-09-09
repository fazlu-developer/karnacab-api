export abstract class SmsPort {
  abstract send(phone: string, body: string): Promise<void>;
}
