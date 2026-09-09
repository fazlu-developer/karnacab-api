import { BookingStatus, PrismaClient, RideProduct, VehicleCategory } from '@prisma/client';
import { hash } from 'bcrypt';
import { CMS_HOME_PROMO_DEFAULT, CMS_PAGES_SEED, CMS_SITE_DEFAULT } from '../src/cms/cms-pages.seed';

const prisma = new PrismaClient();

const DISTRICTS = [
  'Araria',
  'Arwal',
  'Aurangabad',
  'Banka',
  'Begusarai',
  'Bhagalpur',
  'Bhojpur',
  'Buxar',
  'Darbhanga',
  'East Champaran',
  'Gaya',
  'Gopalganj',
  'Jamui',
  'Jehanabad',
  'Kaimur',
  'Katihar',
  'Khagaria',
  'Kishanganj',
  'Lakhisarai',
  'Madhepura',
  'Madhubani',
  'Munger',
  'Muzaffarpur',
  'Nalanda',
  'Nawada',
  'Patna',
  'Purnia',
  'Rohtas',
  'Saharsa',
  'Samastipur',
  'Saran',
  'Sheikhpura',
  'Sheohar',
  'Sitamarhi',
  'Siwan',
  'Supaul',
  'Vaishali',
  'West Champaran',
];

async function main() {
  const bihar = await prisma.state.upsert({
    where: { code: 'BR' },
    update: {},
    create: { code: 'BR', name: 'Bihar' },
  });

  for (const name of DISTRICTS) {
    const code = name.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 12);
    await prisma.district.upsert({
      where: { code },
      update: { name, stateId: bihar.id },
      create: { stateId: bihar.id, code, name },
    });
  }

  const patna = await prisma.district.findFirst({ where: { name: 'Patna' } });

  if ((await prisma.fareRule.count()) === 0) {
    const products: RideProduct[] = [
      RideProduct.LOCAL_CAB,
      RideProduct.ONE_WAY,
      RideProduct.ROUND_WAY,
      RideProduct.RENTAL,
      RideProduct.SCHEDULE,
      RideProduct.OUTSTATION,
      RideProduct.AIRPORT,
      RideProduct.RAILWAY,
      RideProduct.MULTI_STOP,
    ];
    const categories: VehicleCategory[] = [
      VehicleCategory.BIKE,
      VehicleCategory.AUTO,
      VehicleCategory.E_RICKSHAW,
      VehicleCategory.MINI,
      VehicleCategory.SEDAN,
      VehicleCategory.SUV,
      VehicleCategory.TRAVELLER,
    ];

    const perKm: Record<VehicleCategory, number> = {
      BIKE: 800,
      AUTO: 1200,
      E_RICKSHAW: 1000,
      MINI: 1400,
      SEDAN: 1600,
      SUV: 2200,
      TRAVELLER: 2800,
    };

    for (const product of products) {
      for (const category of categories) {
        const rentalHours = product === RideProduct.RENTAL ? 8 : null;
        await prisma.fareRule.create({
          data: {
            districtId: patna?.id,
            product,
            category,
            minKm: product === RideProduct.RENTAL ? 80 : 10,
            includedKm: product === RideProduct.RENTAL ? 80 : 10,
            perKmPaise: perKm[category],
            extraKmPaise: perKm[category] + 200,
            waitingPaise: 200,
            nightPercent: 15,
            gstPercent: 5,
            cancelPaise: 5000,
            driverAllowPaise: product === RideProduct.ROUND_WAY ? 40000 : 0,
            rentalHours,
            active: true,
          },
        });
      }
    }
  }

  await prisma.commissionRule.upsert({
    where: { name: 'default' },
    update: {
      percent: 10,
      onBaseFare: true,
      onGst: false,
      onToll: false,
      onParking: false,
      onWaiting: false,
      onDiscount: false,
      onOther: false,
    },
    create: {
      name: 'default',
      percent: 10,
      onBaseFare: true,
      onGst: false,
      onToll: false,
      onParking: false,
      onWaiting: false,
      onDiscount: false,
      onOther: false,
      active: true,
    },
  });

  await prisma.systemSetting.upsert({
    where: { key: 'ad_impression_paise' },
    update: {},
    create: { key: 'ad_impression_paise', value: '10' },
  });
  await prisma.systemSetting.upsert({
    where: { key: 'ad_click_paise' },
    update: {},
    create: { key: 'ad_click_paise', value: '100' },
  });
  await prisma.systemSetting.upsert({
    where: { key: 'fleet_commission_share_percent' },
    update: {},
    create: { key: 'fleet_commission_share_percent', value: '0' },
  });

  await prisma.systemSetting.upsert({
    where: { key: 'driver_kyc_required_docs' },
    update: { value: JSON.stringify(['LICENSE', 'RC', 'INSURANCE', 'SELFIE', 'ID_PROOF']) },
    create: {
      key: 'driver_kyc_required_docs',
      value: JSON.stringify(['LICENSE', 'RC', 'INSURANCE', 'SELFIE', 'ID_PROOF']),
    },
  });
  await prisma.systemSetting.upsert({
    where: { key: 'driver_kyc_optional_docs' },
    update: { value: JSON.stringify(['PERMIT', 'FITNESS', 'PUC']) },
    create: {
      key: 'driver_kyc_optional_docs',
      value: JSON.stringify(['PERMIT', 'FITNESS', 'PUC']),
    },
  });
  await prisma.systemSetting.upsert({
    where: { key: 'driver_kyc_bank_required' },
    update: { value: 'true' },
    create: { key: 'driver_kyc_bank_required', value: 'true' },
  });

  await prisma.systemSetting.upsert({
    where: { key: 'driver_safety_sos' },
    update: {},
    create: { key: 'driver_safety_sos', value: '112' },
  });
  await prisma.systemSetting.upsert({
    where: { key: 'driver_safety_helpline' },
    update: {},
    create: { key: 'driver_safety_helpline', value: '112' },
  });

  await prisma.systemSetting.upsert({
    where: { key: 'driver_offer_radius_km' },
    update: {},
    create: { key: 'driver_offer_radius_km', value: '30' },
  });

  await prisma.systemSetting.upsert({
    where: { key: 'driver_incentives' },
    update: {
      value: JSON.stringify([
        {
          id: 'five_trips',
          title: 'Complete 5 trips today',
          subtitle: 'Bonus credited to wallet when the target is met',
          bonusPaise: 10000,
          amountPaise: 10000,
          targetTrips: 5,
          targetEarningsPaise: 0,
          period: 'day',
          validFrom: '2026-09-01',
          validTo: '2026-12-31',
        },
        {
          id: 'peak',
          title: 'Peak hour bonus',
          subtitle: 'Hit earnings target on weekday peaks',
          bonusPaise: 5000,
          amountPaise: 5000,
          targetTrips: 3,
          targetEarningsPaise: 30000,
          period: 'day',
          validFrom: '2026-09-01',
          validTo: '2026-12-31',
        },
      ]),
    },
    create: {
      key: 'driver_incentives',
      value: JSON.stringify([
        {
          id: 'five_trips',
          title: 'Complete 5 trips today',
          subtitle: 'Bonus credited to wallet when the target is met',
          bonusPaise: 10000,
          amountPaise: 10000,
          targetTrips: 5,
          targetEarningsPaise: 0,
          period: 'day',
          validFrom: '2026-09-01',
          validTo: '2026-12-31',
        },
        {
          id: 'peak',
          title: 'Peak hour bonus',
          subtitle: 'Hit earnings target on weekday peaks',
          bonusPaise: 5000,
          amountPaise: 5000,
          targetTrips: 3,
          targetEarningsPaise: 30000,
          period: 'day',
          validFrom: '2026-09-01',
          validTo: '2026-12-31',
        },
      ]),
    },
  });

  await prisma.systemSetting.upsert({
    where: { key: 'commission_percent_default' },
    update: { value: '10' },
    create: { key: 'commission_percent_default', value: '10' },
  });

  if ((await prisma.travelPackage.count()) === 0) {
    await prisma.travelPackage.create({
      data: {
        districtId: patna?.id,
        title: 'Patna city darshan',
        destination: 'Patna',
        places: 'Golghar, Gandhi Maidan, Patna Sahib, riverfront',
        durationHours: 8,
        kmIncluded: 80,
        vehicleLabel: 'Sedan',
        pricePaise: 450000,
        inclusions: 'Cab, driver, fuel, parking',
        exclusions: 'Meals, tickets, night stay',
        status: 'PUBLISHED',
      },
    });
  }

  const catalogItems = [
    { slug: 'trip', title: 'Trip', subtitle: 'Local cab, bike and auto', group: 'RIDE' as const, iconKey: 'trip', badge: '100%', sortOrder: 1, featured: true },
    { slug: 'intercity', title: 'Intercity', subtitle: 'City to city rides', group: 'RIDE' as const, iconKey: 'intercity', badge: '7%', sortOrder: 2, featured: true },
    { slug: 'bus-train', title: 'Bus and train', subtitle: 'Station pickups', group: 'RIDE' as const, iconKey: 'bus_train', badge: 'Promo', sortOrder: 3, featured: true },
    { slug: 'pre-book', title: 'Pre-book', subtitle: 'Schedule for later', group: 'RIDE' as const, iconKey: 'prebook', badge: '7%', sortOrder: 4, featured: true },
    { slug: 'seniors', title: 'Seniors', subtitle: 'Assisted rides', group: 'RIDE' as const, iconKey: 'seniors', badge: null, sortOrder: 5, featured: true },
    { slug: 'rental', title: 'Rental', subtitle: 'Hourly packages', group: 'RIDE' as const, iconKey: 'rental', badge: null, sortOrder: 6, featured: false },
    { slug: 'parcel-home', title: 'Parcel', subtitle: 'Send anything nearby', group: 'RIDE' as const, iconKey: 'parcel', badge: null, sortOrder: 7, featured: true },
    { slug: 'send-bike', title: 'Send by bike', subtitle: 'Fast local parcels', group: 'PARCEL' as const, iconKey: 'bike', badge: '25%', sortOrder: 1, featured: true },
    { slug: 'send-mini3w', title: 'Send by Mini 3W', subtitle: 'Medium loads', group: 'PARCEL' as const, iconKey: 'mini3w', badge: null, sortOrder: 2, featured: true },
    { slug: 'send-truck', title: 'Send by truck', subtitle: 'Heavy delivery', group: 'PARCEL' as const, iconKey: 'truck', badge: null, sortOrder: 3, featured: true },
  ];

  for (const item of catalogItems) {
    await prisma.catalogService.upsert({
      where: { slug: item.slug },
      update: item,
      create: item,
    });
  }

  const passwordHash = await hash('ChangeMe@123', 10);
  const dummyProfile = {
    dateOfBirth: new Date('1995-03-12'),
    gender: 'MALE' as const,
    lastLat: 25.5941,
    lastLng: 85.1376,
    lastAddress: 'Patna Junction, Patna, Bihar',
    emergencyName: 'Home emergency',
    emergencyPhone: '9111111111',
    locationUpdatedAt: new Date(),
    profileCompletedAt: new Date(),
    stateId: bihar.id,
    districtId: patna?.id,
  };

  const customer = await prisma.user.upsert({
    where: { email: 'customer@karnacab.local' },
    update: { name: 'Fazlu', phone: '9999999999', ...dummyProfile },
    create: {
      role: 'CUSTOMER',
      name: 'Fazlu',
      email: 'customer@karnacab.local',
      phone: '9999999999',
      passwordHash,
      ...dummyProfile,
      wallets: {
        create: { ownerType: 'CUSTOMER', balancePaise: 250000 },
      },
    },
  });

  await prisma.user.upsert({
    where: { email: 'driver@karnacab.local' },
    update: {
      name: 'Rakesh Kumar',
      phone: '9888888888',
      status: 'ACTIVE',
      ...dummyProfile,
      driver: {
        update: {
          kycStatus: 'verified',
          parcelEnabled: true,
          online: true,
          dutyStatus: 'online',
          emergencyName: 'Family contact',
          emergencyPhone: '9222222222',
        },
      },
    },
    create: {
      role: 'DRIVER',
      name: 'Rakesh Kumar',
      email: 'driver@karnacab.local',
      phone: '9888888888',
      passwordHash,
      ...dummyProfile,
      driver: {
        create: {
          licenseNo: 'BR-DEMO-0001',
          parcelEnabled: true,
          online: true,
          kycStatus: 'verified',
          dutyStatus: 'online',
          emergencyName: 'Family contact',
          emergencyPhone: '9222222222',
        },
      },
      wallets: {
        create: { ownerType: 'DRIVER', balancePaise: 0 },
      },
    },
  });

  const fleetUser = await prisma.user.upsert({
    where: { email: 'fleet@karnacab.local' },
    update: {
      name: 'Patna Fleet',
      phone: '9777777777',
      role: 'FLEET_OWNER',
      status: 'ACTIVE',
      ...dummyProfile,
    },
    create: {
      role: 'FLEET_OWNER',
      name: 'Patna Fleet',
      email: 'fleet@karnacab.local',
      phone: '9777777777',
      passwordHash,
      ...dummyProfile,
      wallets: { create: { ownerType: 'FLEET_OWNER', balancePaise: 0 } },
    },
  });
  const fleet = await prisma.fleetOwner.upsert({
    where: { userId: fleetUser.id },
    update: { tradeName: 'Patna Demo Fleet' },
    create: { userId: fleetUser.id, tradeName: 'Patna Demo Fleet', gstin: '10AABCU9603R1ZX' },
  });
  const driverUser = await prisma.user.findUnique({
    where: { email: 'driver@karnacab.local' },
    include: { driver: true },
  });
  if (driverUser?.driver && patna) {
    await prisma.driver.update({
      where: { id: driverUser.driver.id },
      data: { fleetOwnerId: fleet.id },
    });
    await prisma.vehicle.upsert({
      where: { registrationNo: 'BR01FL0001' },
      update: {
        fleetOwnerId: fleet.id,
        driverId: driverUser.driver.id,
        status: 'available',
        brand: 'Maruti',
        model: 'Dzire',
        year: 2022,
      },
      create: {
        fleetOwnerId: fleet.id,
        driverId: driverUser.driver.id,
        districtId: patna.id,
        category: 'SEDAN',
        registrationNo: 'BR01FL0001',
        brand: 'Maruti',
        model: 'Dzire',
        year: 2022,
        status: 'available',
      },
    });
  }

  await prisma.user.upsert({
    where: { email: 'admin@karnacab.local' },
    update: { name: 'KarnaCab Admin', phone: '9444444444', role: 'ADMIN', status: 'ACTIVE', ...dummyProfile },
    create: {
      role: 'ADMIN',
      name: 'KarnaCab Admin',
      email: 'admin@karnacab.local',
      phone: '9444444444',
      passwordHash,
      ...dummyProfile,
    },
  });

  const stateUser = await prisma.user.upsert({
    where: { email: 'statehead@karnacab.local' },
    update: { name: 'Bihar State Head', phone: '9666666666', role: 'STATE_HEAD', status: 'ACTIVE', ...dummyProfile, districtId: null },
    create: {
      role: 'STATE_HEAD',
      name: 'Bihar State Head',
      email: 'statehead@karnacab.local',
      phone: '9666666666',
      passwordHash,
      ...dummyProfile,
      districtId: null,
    },
  });

  const districtUser = await prisma.user.upsert({
    where: { email: 'district@karnacab.local' },
    update: { name: 'Patna District Head', phone: '9555555555', role: 'DISTRICT_HEAD', status: 'ACTIVE', ...dummyProfile },
    create: {
      role: 'DISTRICT_HEAD',
      name: 'Patna District Head',
      email: 'district@karnacab.local',
      phone: '9555555555',
      passwordHash,
      ...dummyProfile,
      wallets: { create: { ownerType: 'DISTRICT_HEAD', balancePaise: 0 } },
    },
  });

  if (patna) {
    const existing = await prisma.franchise.findFirst({
      where: { ownerUserId: districtUser.id, districtId: patna.id },
    });
    if (!existing) {
      await prisma.franchise.create({
        data: {
          districtId: patna.id,
          stateId: bihar.id,
          ownerUserId: districtUser.id,
          parentUserId: stateUser.id,
          kind: 'DISTRICT_HEAD',
          status: 'ACTIVE',
          activeDistrictKey: String(patna.id),
          tradeName: 'Patna Exclusive District',
          kycStatus: 'verified',
          agreementStatus: 'signed',
          startsOn: new Date(),
        },
      });
    } else {
      await prisma.franchise.update({
        where: { id: existing.id },
        data: {
          status: 'ACTIVE',
          activeDistrictKey: String(patna.id),
          parentUserId: stateUser.id,
          kind: 'DISTRICT_HEAD',
          tradeName: 'Patna Exclusive District',
        },
      });
    }
  }

  const adsUser = await prisma.user.upsert({
    where: { email: 'ads@karnacab.local' },
    update: { name: 'Patna Hotels Ads', phone: '9333333333', role: 'ADVERTISER', status: 'ACTIVE', ...dummyProfile },
    create: {
      role: 'ADVERTISER',
      name: 'Patna Hotels Ads',
      email: 'ads@karnacab.local',
      phone: '9333333333',
      passwordHash,
      ...dummyProfile,
    },
  });
  if (patna) {
    const existingAd = await prisma.adCampaign.findFirst({
      where: { advertiserUserId: adsUser.id, title: 'Patna weekend stay' },
    });
    if (!existingAd) {
      await prisma.adCampaign.create({
        data: {
          advertiserUserId: adsUser.id,
          businessName: 'Ganga View Hotel',
          title: 'Patna weekend stay',
          category: 'hotels',
          targetCity: 'Patna',
          districtId: patna.id,
          startsOn: new Date('2026-09-01T00:00:00.000Z'),
          endsOn: new Date('2026-12-31T23:59:59.000Z'),
          budgetPaise: 500000,
          status: 'published',
          publishedAt: new Date(),
          ctaUrl: 'https://karnacab.local/ads/hotel',
        },
      });
    }
  }

  await prisma.user.upsert({
    where: { email: 'super@karnacab.local' },
    update: { name: 'KarnaCab Super Admin', phone: '9000000001', role: 'SUPER_ADMIN', status: 'ACTIVE', ...dummyProfile },
    create: {
      role: 'SUPER_ADMIN',
      name: 'KarnaCab Super Admin',
      email: 'super@karnacab.local',
      phone: '9000000001',
      passwordHash,
      ...dummyProfile,
    },
  });

  await prisma.user.upsert({
    where: { email: 'franchise@karnacab.local' },
    update: { name: 'Gaya Franchise Applicant', phone: '9000000002', role: 'FRANCHISE', status: 'ACTIVE', ...dummyProfile },
    create: {
      role: 'FRANCHISE',
      name: 'Gaya Franchise Applicant',
      email: 'franchise@karnacab.local',
      phone: '9000000002',
      passwordHash,
      ...dummyProfile,
      wallets: { create: { ownerType: 'FRANCHISE', balancePaise: 0 } },
    },
  });

  const corporateUser = await prisma.user.upsert({
    where: { email: 'corporate@karnacab.local' },
    update: { name: 'Bihar Industries Corp', phone: '9000000003', role: 'CORPORATE', status: 'ACTIVE', ...dummyProfile },
    create: {
      role: 'CORPORATE',
      name: 'Bihar Industries Corp',
      email: 'corporate@karnacab.local',
      phone: '9000000003',
      passwordHash,
      ...dummyProfile,
      wallets: { create: { ownerType: 'CORPORATE', balancePaise: 100000 } },
    },
  });
  await prisma.corporateAccount.upsert({
    where: { ownerUserId: corporateUser.id },
    update: { companyName: 'Bihar Industries Pvt Ltd', gstin: '10AABCI1234L1Z5' },
    create: {
      ownerUserId: corporateUser.id,
      companyName: 'Bihar Industries Pvt Ltd',
      gstin: '10AABCI1234L1Z5',
      contactName: 'Corporate Desk',
      contactPhone: '9000000003',
      contactEmail: 'corporate@karnacab.local',
      districtId: patna?.id,
      status: 'active',
    },
  });

  const recents = [
    { title: 'New Delhi Railway Station Gate No. 2', subtitle: 'Ajmeri Gate, Delhi', address: 'New Delhi Railway Station Gate No. 2', lat: 28.643, lng: 77.219 },
    { title: 'Patna Sahib', subtitle: 'Harmandir, Patna', address: 'Takht Sri Patna Sahib', lat: 25.6206, lng: 85.181 },
    { title: 'Golghar', subtitle: 'Gandhi Maidan, Patna', address: 'Golghar, Patna', lat: 25.6208, lng: 85.139 },
  ];
  if ((await prisma.userPlace.count({ where: { userId: customer.id } })) === 0) {
    await prisma.userPlace.createMany({
      data: recents.map((place) => ({
        userId: customer.id,
        kind: 'RECENT' as const,
        ...place,
      })),
    });
  }
  if ((await prisma.userPlace.count({ where: { userId: customer.id, kind: 'SAVED' } })) === 0) {
    await prisma.userPlace.create({
      data: {
        userId: customer.id,
        kind: 'SAVED',
        title: 'Home',
        subtitle: 'Patna Junction',
        address: 'Patna Junction, Patna, Bihar',
        lat: 25.609,
        lng: 85.144,
      },
    });
  }

  await prisma.coupon.upsert({
    where: { code: 'PATNA10' },
    update: { percent: 10, kind: 'percent', districtId: patna?.id ?? null, active: true },
    create: {
      code: 'PATNA10',
      title: '10% off in Patna',
      subtitle: 'Local & one-way from Patna',
      percent: 10,
      kind: 'percent',
      districtId: patna?.id,
      startsOn: new Date('2026-01-01'),
      endsOn: new Date('2026-12-31'),
      active: true,
    },
  });
  await prisma.coupon.upsert({
    where: { code: 'WELCOMEFLAT' },
    update: { amountPaise: 5000, active: true },
    create: {
      code: 'WELCOMEFLAT',
      title: '₹50 welcome off',
      subtitle: 'Flat discount on your payable fare',
      amountPaise: 5000,
      kind: 'fixed',
      minFarePaise: 10000,
      audience: 'new_user',
      startsOn: new Date('2026-01-01'),
      endsOn: new Date('2026-12-31'),
      active: true,
    },
  });

  if ((await prisma.familyMember.count({ where: { userId: customer.id } })) === 0) {
    await prisma.familyMember.create({
      data: {
        userId: customer.id,
        name: 'Aisha',
        phone: '9876543210',
        relation: 'Sister',
      },
    });
  }

  if ((await prisma.userNotification.count({ where: { userId: customer.id } })) === 0) {
    await prisma.userNotification.create({
      data: {
        userId: customer.id,
        title: 'Welcome to KarnaCab',
        body: 'Saved Home, family member Aisha, and coupons PATNA10 / WELCOMEFLAT are ready.',
        kind: 'offers',
      },
    });
  }

  if ((await prisma.supportFaq.count()) === 0) {
    await prisma.supportFaq.createMany({
      data: [
        { audience: 'customer', sortOrder: 1, question: 'Where is my booking?', answer: 'Open My bookings. Upcoming, scheduled, and history are separate tabs.' },
        { audience: 'customer', sortOrder: 2, question: 'How do I complain about a trip?', answer: 'Open the trip and tap Complaint, or create a booking-linked ticket in Support. Attach a photo if it helps.' },
        { audience: 'customer', sortOrder: 3, question: 'How are payments confirmed?', answer: 'Cash is confirmed by the driver. UPI and card wait for a signed webhook. The app cannot mark a payment captured.' },
        { audience: 'driver', sortOrder: 1, question: 'Why am I not getting requests?', answer: 'Stay online, complete KYC, and keep documents valid. Finish the current job before accepting another.' },
        { audience: 'driver', sortOrder: 2, question: 'When do I get paid?', answer: 'Completed trip earnings post to your wallet. Incentives appear when the target is met.' },
        { audience: 'ops', sortOrder: 1, question: 'How do we resolve tickets?', answer: 'Use GET /support/queue then PATCH /support/tickets/:id with resolved and a customer-visible reply.' },
      ],
    });
  }

  const demoVehicle = await prisma.vehicle.findUnique({ where: { registrationNo: 'BR01FL0001' } });
  const demoDriverRow = await prisma.driver.findFirst({
    where: { user: { email: 'driver@karnacab.local' } },
  });
  if (patna && demoDriverRow && demoVehicle && (await prisma.booking.count({ where: { publicRef: { startsWith: 'KCDEMO' } } })) === 0) {
    const rideDemos: Array<{
      product: RideProduct;
      status: BookingStatus;
      drop: string;
      extra?: Record<string, unknown>;
    }> = [
      { product: RideProduct.LOCAL_CAB, status: BookingStatus.COMPLETED, drop: 'Gandhi Maidan, Patna' },
      { product: RideProduct.ONE_WAY, status: BookingStatus.CONFIRMED, drop: 'Gaya Junction' },
      { product: RideProduct.ROUND_WAY, status: BookingStatus.DRIVER_ASSIGNED, drop: 'Rajgir' },
      { product: RideProduct.RENTAL, status: BookingStatus.CONFIRMED, drop: 'Hourly Patna city' },
      { product: RideProduct.SCHEDULE, status: BookingStatus.PENDING, drop: 'Secretariat, Patna', extra: { scheduledAt: new Date(Date.now() + 86400000) } },
      { product: RideProduct.OUTSTATION, status: BookingStatus.REQUESTED, drop: 'Muzaffarpur' },
      { product: RideProduct.AIRPORT, status: BookingStatus.DRIVER_ARRIVING, drop: 'Jay Prakash Narayan Airport', extra: { flightNumber: 'AI123', terminal: 'T1' } },
      { product: RideProduct.RAILWAY, status: BookingStatus.ASSIGNED, drop: 'Patna Junction', extra: { trainNumber: '12393' } },
      { product: RideProduct.MULTI_STOP, status: BookingStatus.ONGOING, drop: 'Patna Sahib' },
    ];
    for (const [index, row] of rideDemos.entries()) {
      await prisma.booking.create({
        data: {
          publicRef: `KCDEMO${String(index + 1).padStart(4, '0')}`,
          customerId: customer.id,
          districtId: patna.id,
          product: row.product,
          category: VehicleCategory.SEDAN,
          status: row.status,
          pickupText: 'Patna Junction, Patna',
          dropText: row.drop,
          pickupLat: 25.609,
          pickupLng: 85.144,
          dropLat: 25.612,
          dropLng: 85.141,
          distanceKm: 12,
          quotePaise: 20580,
          quoteSnapshot: { source: 'server', totalPaise: 20580, product: row.product, category: 'SEDAN' },
          driverId: demoDriverRow.id,
          vehicleId: demoVehicle.id,
          startOtp: '111111',
          endOtp: '222222',
          passengerName: 'Fazlu',
          passengerPhone: '9999999999',
          ...(row.extra ?? {}),
        },
      });
    }
  }

  await prisma.systemSetting.upsert({
    where: { key: 'cms_site' },
    update: {},
    create: { key: 'cms_site', value: JSON.stringify(CMS_SITE_DEFAULT) },
  });
  await prisma.systemSetting.upsert({
    where: { key: 'cms_home_promo' },
    update: {},
    create: { key: 'cms_home_promo', value: JSON.stringify(CMS_HOME_PROMO_DEFAULT) },
  });

  for (const page of CMS_PAGES_SEED) {
    await prisma.cmsPage.upsert({
      where: { slug: page.slug },
      update: {
        title: page.title,
        eyebrow: page.eyebrow,
        seoTitle: page.seoTitle,
        seoDescription: page.seoDescription,
        lede: page.lede,
        body: page.body,
        template: page.template,
        leadType: page.leadType,
        registerKind: page.registerKind,
        productKey: page.productKey,
        navGroup: page.navGroup,
        navLabel: page.navLabel,
        sortOrder: page.sortOrder,
        published: true,
      },
      create: {
        slug: page.slug,
        title: page.title,
        eyebrow: page.eyebrow,
        seoTitle: page.seoTitle,
        seoDescription: page.seoDescription,
        lede: page.lede,
        body: page.body,
        template: page.template,
        leadType: page.leadType,
        registerKind: page.registerKind,
        productKey: page.productKey,
        navGroup: page.navGroup,
        navLabel: page.navLabel,
        sortOrder: page.sortOrder,
        published: true,
      },
    });
  }
}

main()
  .then(async () => {
    await prisma.$disconnect();
  })
  .catch(async (error) => {
    console.error(error);
    await prisma.$disconnect();
    process.exit(1);
  });
