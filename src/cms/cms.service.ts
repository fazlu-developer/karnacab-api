import { Injectable, NotFoundException } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { CatalogService } from '../catalog/catalog.service';
import { SupportService } from '../support/support.service';
import { FareEngine } from '../ride-engine/fare.engine';
import { PatchCmsPageDto, PatchCmsSiteDto } from './dto/cms.dto';
import { CMS_HOME_PROMO_DEFAULT, CMS_SITE_DEFAULT } from './cms-pages.seed';

@Injectable()
export class CmsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly catalog: CatalogService,
    private readonly support: SupportService,
    private readonly fares: FareEngine,
  ) {}

  async site() {
    const [pages, siteRow, promoRow, catalog, faqs, rentalPackages] = await Promise.all([
      this.prisma.cmsPage.findMany({
        where: { published: true },
        orderBy: [{ navGroup: 'asc' }, { sortOrder: 'asc' }],
      }),
      this.prisma.systemSetting.findUnique({ where: { key: 'cms_site' } }),
      this.prisma.systemSetting.findUnique({ where: { key: 'cms_home_promo' } }),
      this.catalog.listPublic(),
      this.support.faqs('customer'),
      this.fares.rentalPackages(),
    ]);

    return {
      site: this.parseSite(siteRow?.value),
      promo: this.parsePromo(promoRow?.value),
      pages: pages.map((row) => this.present(row)),
      nav: this.navFrom(pages.map((row) => this.present(row))),
      catalog: {
        ...catalog,
        rentalPackages,
      },
      faqs: faqs.faqs,
    };
  }

  async page(slug: string) {
    const row = await this.prisma.cmsPage.findUnique({ where: { slug } });
    if (!row || !row.published) {
      throw new NotFoundException('Page not found');
    }
    return this.present(row);
  }

  async adminList() {
    const rows = await this.prisma.cmsPage.findMany({
      orderBy: [{ navGroup: 'asc' }, { sortOrder: 'asc' }],
    });
    return { pages: rows.map((row) => this.present(row)) };
  }

  async patchPage(id: bigint, dto: PatchCmsPageDto) {
    const existing = await this.prisma.cmsPage.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException('Page not found');
    }
    const updated = await this.prisma.cmsPage.update({
      where: { id },
      data: {
        title: dto.title,
        eyebrow: dto.eyebrow,
        seoTitle: dto.seoTitle,
        seoDescription: dto.seoDescription,
        lede: dto.lede,
        body: dto.body as Prisma.InputJsonValue | undefined,
        published: dto.published,
        navLabel: dto.navLabel,
        sortOrder: dto.sortOrder,
      },
    });
    return this.present(updated);
  }

  async patchSite(dto: PatchCmsSiteDto) {
    const current = this.parseSite(
      (await this.prisma.systemSetting.findUnique({ where: { key: 'cms_site' } }))?.value,
    );
    const next = { ...current, ...this.omitUndefined(dto) };
    await this.prisma.systemSetting.upsert({
      where: { key: 'cms_site' },
      update: { value: JSON.stringify(next) },
      create: { key: 'cms_site', value: JSON.stringify(next) },
    });
    if (dto.promo) {
      await this.prisma.systemSetting.upsert({
        where: { key: 'cms_home_promo' },
        update: { value: JSON.stringify(dto.promo) },
        create: { key: 'cms_home_promo', value: JSON.stringify(dto.promo) },
      });
    }
    return { site: next, promo: dto.promo ?? this.parsePromo((await this.prisma.systemSetting.findUnique({ where: { key: 'cms_home_promo' } }))?.value) };
  }

  private present(row: {
    id: bigint;
    slug: string;
    title: string;
    eyebrow: string | null;
    seoTitle: string | null;
    seoDescription: string | null;
    lede: string;
    body: Prisma.JsonValue;
    template: string;
    leadType: string | null;
    registerKind: string | null;
    productKey: string | null;
    navGroup: string;
    navLabel: string;
    sortOrder: number;
    published: boolean;
    updatedAt: Date;
  }) {
    return {
      id: row.id.toString(),
      slug: row.slug,
      path: row.slug === 'home' ? '/' : `/${row.slug}`,
      title: row.title,
      eyebrow: row.eyebrow,
      seoTitle: row.seoTitle || `${row.title} | KarnaCab`,
      seoDescription: row.seoDescription || row.lede,
      lede: row.lede,
      body: row.body,
      template: row.template,
      leadType: row.leadType,
      registerKind: row.registerKind,
      productKey: row.productKey,
      navGroup: row.navGroup,
      navLabel: row.navLabel,
      sortOrder: row.sortOrder,
      published: row.published,
      updatedAt: row.updatedAt.toISOString(),
    };
  }

  private navFrom(pages: Array<{ navGroup: string; slug: string; path: string; navLabel: string; sortOrder: number }>) {
    const groups = ['primary', 'rides', 'services', 'company', 'legal'] as const;
    return groups.map((group) => ({
      group,
      items: pages
        .filter((page) => page.navGroup === group && page.slug !== 'home')
        .sort((a, b) => a.sortOrder - b.sortOrder)
        .map((page) => ({ slug: page.slug, path: page.path, label: page.navLabel })),
    }));
  }

  private parseSite(raw?: string | null) {
    if (!raw) {
      return { ...CMS_SITE_DEFAULT };
    }
    try {
      return { ...CMS_SITE_DEFAULT, ...(JSON.parse(raw) as Record<string, unknown>) };
    } catch {
      return { ...CMS_SITE_DEFAULT };
    }
  }

  private parsePromo(raw?: string | null) {
    if (!raw) {
      return { ...CMS_HOME_PROMO_DEFAULT };
    }
    try {
      return { ...CMS_HOME_PROMO_DEFAULT, ...(JSON.parse(raw) as Record<string, unknown>) };
    } catch {
      return { ...CMS_HOME_PROMO_DEFAULT };
    }
  }

  private omitUndefined(dto: PatchCmsSiteDto) {
    return Object.fromEntries(Object.entries(dto).filter(([key, value]) => key !== 'promo' && value !== undefined));
  }
}
