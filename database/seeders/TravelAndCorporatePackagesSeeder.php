<?php

namespace Database\Seeders;

use App\Support\CorporatePlanSchema;
use App\Support\TravelPackageSchema;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TravelAndCorporatePackagesSeeder extends Seeder
{
    public function run(): void
    {
        TravelPackageSchema::ensure();
        CorporatePlanSchema::ensure();
        $this->seedTravel();
        $this->seedCorporate();
    }

    private function seedTravel(): void
    {
        if (! Schema::hasTable('travel_packages')) {
            return;
        }
        $now = now();
        $dates = $this->upcomingSaturdays(12);
        foreach ($this->tours() as $tour) {
            $row = $this->travelRow($tour, $dates, $now);
            $existing = DB::table('travel_packages')->where('title', $tour['title'])->first();
            if ($existing) {
                unset($row['created_at']);
                DB::table('travel_packages')->where('id', $existing->id)->update(
                    array_filter($row, fn ($key) => Schema::hasColumn('travel_packages', $key), ARRAY_FILTER_USE_KEY)
                );
            } else {
                DB::table('travel_packages')->insert(
                    array_filter($row, fn ($key) => Schema::hasColumn('travel_packages', $key), ARRAY_FILTER_USE_KEY)
                );
            }
        }
    }

    private function seedCorporate(): void
    {
        if (! Schema::hasTable('corporate_plans')) {
            return;
        }
        $now = now();
        foreach ($this->corporatePlans() as $plan) {
            $row = array_merge($plan, ['updated_at' => $now]);
            $existing = DB::table('corporate_plans')->where('plan_key', $plan['plan_key'])->first();
            if ($existing) {
                DB::table('corporate_plans')->where('id', $existing->id)->update(
                    array_filter($row, fn ($key) => Schema::hasColumn('corporate_plans', $key), ARRAY_FILTER_USE_KEY)
                );
            } else {
                $row['created_at'] = $now;
                DB::table('corporate_plans')->insert(
                    array_filter($row, fn ($key) => Schema::hasColumn('corporate_plans', $key), ARRAY_FILTER_USE_KEY)
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tour
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function travelRow(array $tour, array $dates, Carbon $now): array
    {
        $nights = (int) $tour['nights'];
        $days = $nights + 1;

        return [
            'title' => $tour['title'],
            'name' => $tour['title'],
            'destination' => $tour['destination'],
            'origin' => 'Saharsa, Bihar',
            'region' => $tour['region'],
            'category' => $tour['category'],
            'price_paise' => (int) $tour['price_paise'],
            'nights' => $nights,
            'duration_hours' => $days * 24,
            'duration_label' => $nights > 0 ? $nights.'N / '.$days.'D' : $days.'D',
            'km_included' => (int) $tour['km'],
            'vehicle_category' => $tour['vehicle'],
            'vehicle_label' => $tour['vehicle'] === 'TRAVELLER' ? 'Tempo Traveller' : ($tour['vehicle'] === 'SUV' ? 'Innova / SUV' : 'Private Sedan'),
            'driver_label' => 'Dedicated driver from Saharsa',
            'min_pax' => (int) $tour['min_pax'],
            'places' => $tour['places'],
            'highlights' => json_encode($tour['highlights']),
            'inclusions' => implode(', ', $tour['inclusions']),
            'exclusions' => implode(', ', $tour['exclusions']),
            'itinerary' => json_encode($tour['itinerary']),
            'gallery' => json_encode($tour['gallery']),
            'image_url' => $tour['gallery'][0],
            'available_dates' => json_encode($dates),
            'popular' => ! empty($tour['popular']),
            'status' => 'PUBLISHED',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return list<string>
     */
    private function upcomingSaturdays(int $count): array
    {
        $dates = [];
        $day = Carbon::now()->next(Carbon::SATURDAY);
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $day->toDateString();
            $day = $day->copy()->addWeek();
        }

        return $dates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tours(): array
    {
        return [
            [
                'title' => 'Manali Snow Escape',
                'destination' => 'Manali',
                'region' => 'Himachal',
                'category' => 'WEEKEND',
                'price_paise' => 1499900,
                'nights' => 3,
                'km' => 1800,
                'vehicle' => 'SUV',
                'min_pax' => 2,
                'popular' => true,
                'places' => 'Hadimba Temple, Solang Valley, Mall Road, Rohtang viewpoint',
                'gallery' => [
                    'https://images.unsplash.com/photo-1626621341517-bbf3d9990a23?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Snow peaks & Solang Valley', 'Private SUV from Saharsa', 'Hotel stay with breakfast', 'Local sightseeing included'],
                'inclusions' => ['Private cab', 'Driver allowance', 'Hotel (twin share)', 'Daily breakfast', 'Toll & parking'],
                'exclusions' => ['Flights / trains', 'Lunch & dinner', 'Personal shopping', 'Rohtang permit if extra'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Saharsa to Chandigarh overnight', 'detail' => 'Start in a comfortable SUV. Night halt en route.'],
                    ['day' => 'Day 2', 'title' => 'Arrive Manali, Mall Road', 'detail' => 'Check-in, evening stroll and Hadimba Temple.'],
                    ['day' => 'Day 3', 'title' => 'Solang Valley', 'detail' => 'Snow activities and mountain views.'],
                    ['day' => 'Day 4', 'title' => 'Return journey', 'detail' => 'Drive back towards Bihar with photo stops.'],
                ],
            ],
            [
                'title' => 'Shimla Queen of Hills',
                'destination' => 'Shimla',
                'region' => 'Himachal',
                'category' => 'OUTSTATION',
                'price_paise' => 1299900,
                'nights' => 3,
                'km' => 1700,
                'vehicle' => 'SEDAN',
                'min_pax' => 2,
                'popular' => true,
                'places' => 'Mall Road, Kufri, Christ Church, Jakhu',
                'gallery' => [
                    'https://images.unsplash.com/photo-1597073517494-8b26ca2f64c6?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1587474260584-136574528ed5?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Toy-town Mall Road', 'Kufri excursion', 'Sedan with dedicated driver'],
                'inclusions' => ['Private sedan', 'Hotel stay', 'Breakfast', 'Kufri sightseeing'],
                'exclusions' => ['Meals besides breakfast', 'Adventure sports'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Depart Saharsa', 'detail' => 'Long-drive start with rest stops.'],
                    ['day' => 'Day 2', 'title' => 'Shimla Mall Road', 'detail' => 'Check-in and ridge walk.'],
                    ['day' => 'Day 3', 'title' => 'Kufri', 'detail' => 'Hill views and local snacks.'],
                    ['day' => 'Day 4', 'title' => 'Return', 'detail' => 'Drive back to Saharsa.'],
                ],
            ],
            [
                'title' => 'Nainital Lake Weekend',
                'destination' => 'Nainital',
                'region' => 'Uttarakhand',
                'category' => 'WEEKEND',
                'price_paise' => 1199900,
                'nights' => 2,
                'km' => 1400,
                'vehicle' => 'SUV',
                'min_pax' => 2,
                'popular' => true,
                'places' => 'Naini Lake, Mallital, Snow View, Naina Devi',
                'gallery' => [
                    'https://images.unsplash.com/photo-1605640840605-14ac1855827b?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1548013146-72479768bada?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Naini Lake boating', 'Hill station hotels', 'Ideal 2N weekend'],
                'inclusions' => ['SUV', 'Hotel 2 nights', 'Breakfast', 'Local sightseeing'],
                'exclusions' => ['Boat tickets extra', 'Dinner'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Drive to Nainital', 'detail' => 'Evening lake-side walk.'],
                    ['day' => 'Day 2', 'title' => 'Local sightseeing', 'detail' => 'Naina Devi and Snow View.'],
                    ['day' => 'Day 3', 'title' => 'Return to Saharsa', 'detail' => 'Comfortable SUV drop.'],
                ],
            ],
            [
                'title' => 'Mussoorie Cloud Walk',
                'destination' => 'Mussoorie',
                'region' => 'Uttarakhand',
                'category' => 'OUTSTATION',
                'price_paise' => 1349900,
                'nights' => 3,
                'km' => 1550,
                'vehicle' => 'SUV',
                'min_pax' => 2,
                'popular' => false,
                'places' => 'Kempty Falls, Mall Road, Gun Hill, Camel’s Back',
                'gallery' => [
                    'https://images.unsplash.com/photo-1582510003544-4d00b7f74220?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Kempty Falls', 'Colonial Mall Road', 'Family SUV tour'],
                'inclusions' => ['SUV', 'Hotel', 'Breakfast', 'Sightseeing'],
                'exclusions' => ['Ropeway tickets', 'Lunch'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Depart Saharsa', 'detail' => 'Overnight / long drive.'],
                    ['day' => 'Day 2', 'title' => 'Mussoorie Mall', 'detail' => 'Check-in and sunset point.'],
                    ['day' => 'Day 3', 'title' => 'Kempty Falls', 'detail' => 'Waterfall and local cafes.'],
                    ['day' => 'Day 4', 'title' => 'Return', 'detail' => 'Drop at Saharsa.'],
                ],
            ],
            [
                'title' => 'Jaipur Royal Heritage',
                'destination' => 'Jaipur',
                'region' => 'Rajasthan',
                'category' => 'PACKAGES',
                'price_paise' => 1599900,
                'nights' => 3,
                'km' => 2000,
                'vehicle' => 'SEDAN',
                'min_pax' => 2,
                'popular' => true,
                'places' => 'Amber Fort, City Palace, Hawa Mahal, Jal Mahal',
                'gallery' => [
                    'https://images.unsplash.com/photo-1477587458883-47145f127ba8?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1524492412937-b28074a5d7da?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Pink City forts', 'Guided city circuit', 'Heritage photo stops'],
                'inclusions' => ['Private cab', 'Hotel', 'Breakfast', 'Monument circuit'],
                'exclusions' => ['Monument tickets', 'Camel / elephant rides'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Travel to Jaipur', 'detail' => 'Hotel check-in, Hawa Mahal night lights.'],
                    ['day' => 'Day 2', 'title' => 'Amber & City Palace', 'detail' => 'Full heritage day.'],
                    ['day' => 'Day 3', 'title' => 'Jal Mahal & bazaars', 'detail' => 'Shopping and photos.'],
                    ['day' => 'Day 4', 'title' => 'Return', 'detail' => 'Drive back to Saharsa.'],
                ],
            ],
            [
                'title' => 'Udaipur Lakes & Palaces',
                'destination' => 'Udaipur',
                'region' => 'Rajasthan',
                'category' => 'PACKAGES',
                'price_paise' => 1899900,
                'nights' => 4,
                'km' => 2400,
                'vehicle' => 'SUV',
                'min_pax' => 2,
                'popular' => false,
                'places' => 'City Palace, Lake Pichola, Fateh Sagar, Saheliyon ki Bari',
                'gallery' => [
                    'https://images.unsplash.com/photo-1617518128019-c70eeb62b57d?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1596176530529-78163a4f7af2?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Lake Pichola evening', 'City Palace', 'Romantic 4N circuit'],
                'inclusions' => ['SUV', 'Hotel 4 nights', 'Breakfast', 'City sightseeing'],
                'exclusions' => ['Boat ride tickets', 'Dinner'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Depart Saharsa', 'detail' => 'Long-drive start.'],
                    ['day' => 'Day 2', 'title' => 'Arrive Udaipur', 'detail' => 'Lake-side evening.'],
                    ['day' => 'Day 3', 'title' => 'Palaces', 'detail' => 'City Palace and gardens.'],
                    ['day' => 'Day 4', 'title' => 'Leisure', 'detail' => 'Optional boat ride.'],
                    ['day' => 'Day 5', 'title' => 'Return', 'detail' => 'Drop Saharsa.'],
                ],
            ],
            [
                'title' => 'Goa Beach Getaway',
                'destination' => 'Goa',
                'region' => 'South',
                'category' => 'WEEKEND',
                'price_paise' => 2199900,
                'nights' => 4,
                'km' => 2800,
                'vehicle' => 'SUV',
                'min_pax' => 2,
                'popular' => true,
                'places' => 'Baga, Calangute, Fort Aguada, Old Goa churches',
                'gallery' => [
                    'https://images.unsplash.com/photo-1512343879784-a960bf40e1f1?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Beach hotels', 'North Goa circuit', 'Sunset points'],
                'inclusions' => ['SUV', 'Hotel', 'Breakfast', 'North Goa sightseeing'],
                'exclusions' => ['Water sports', 'Nightlife'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Start from Saharsa', 'detail' => 'Comfortable long drive.'],
                    ['day' => 'Day 2', 'title' => 'Arrive Goa', 'detail' => 'Beach evening.'],
                    ['day' => 'Day 3', 'title' => 'North Goa', 'detail' => 'Baga, Calangute, Aguada.'],
                    ['day' => 'Day 4', 'title' => 'Old Goa', 'detail' => 'Churches and spice stop.'],
                    ['day' => 'Day 5', 'title' => 'Return', 'detail' => 'Drive back.'],
                ],
            ],
            [
                'title' => 'Kerala Backwaters',
                'destination' => 'Alleppey',
                'region' => 'South',
                'category' => 'PACKAGES',
                'price_paise' => 2499900,
                'nights' => 5,
                'km' => 3200,
                'vehicle' => 'SUV',
                'min_pax' => 2,
                'popular' => false,
                'places' => 'Alleppey houseboat, Fort Kochi, Lulu Mall',
                'gallery' => [
                    'https://images.unsplash.com/photo-1602216056096-3b40cc0c9944?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1593693397690-362cb9666fc2?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Backwater cruise day', 'Fort Kochi walk', 'Premium 5N South India'],
                'inclusions' => ['SUV', 'Hotels', 'Breakfast', 'Houseboat day (shared)'],
                'exclusions' => ['Flights if you fly one-way', 'Ayurveda spa'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Depart Saharsa', 'detail' => 'Long-drive / overnight.'],
                    ['day' => 'Day 2', 'title' => 'Kochi', 'detail' => 'Fort Kochi Chinese nets.'],
                    ['day' => 'Day 3', 'title' => 'Alleppey', 'detail' => 'Backwaters.'],
                    ['day' => 'Day 4', 'title' => 'Leisure', 'detail' => 'Beach or spa optional.'],
                    ['day' => 'Day 5', 'title' => 'Return start', 'detail' => 'Begin drive home.'],
                    ['day' => 'Day 6', 'title' => 'Saharsa drop', 'detail' => 'End of tour.'],
                ],
            ],
            [
                'title' => 'Bodh Gaya Spiritual Circuit',
                'destination' => 'Bodh Gaya',
                'region' => 'Bihar',
                'category' => 'PILGRIMAGE',
                'price_paise' => 499900,
                'nights' => 1,
                'km' => 280,
                'vehicle' => 'SEDAN',
                'min_pax' => 2,
                'popular' => true,
                'places' => 'Mahabodhi Temple, Great Buddha, Sujata village',
                'gallery' => [
                    'https://images.unsplash.com/photo-1548013146-72479768bada?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1582510003544-4d00b7f74220?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Mahabodhi Temple', 'Same-state easy tour', '1N hotel stay'],
                'inclusions' => ['Sedan', 'Hotel 1 night', 'Driver', 'Toll'],
                'exclusions' => ['Donation / puja', 'Meals'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Saharsa to Bodh Gaya', 'detail' => 'Evening temple visit.'],
                    ['day' => 'Day 2', 'title' => 'Circuit & return', 'detail' => 'Buddha statue then drop Saharsa.'],
                ],
            ],
            [
                'title' => 'Varanasi Ganga Aarti',
                'destination' => 'Varanasi',
                'region' => 'Uttarakhand',
                'category' => 'PILGRIMAGE',
                'price_paise' => 799900,
                'nights' => 2,
                'km' => 520,
                'vehicle' => 'SEDAN',
                'min_pax' => 2,
                'popular' => false,
                'places' => 'Dashashwamedh Ghat, Kashi Vishwanath, Sarnath',
                'gallery' => [
                    'https://images.unsplash.com/photo-1561361513-2d000a50f0dc?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1597074260584-136574528ed5?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Evening Ganga Aarti', 'Sarnath morning', 'Hotel near ghats'],
                'inclusions' => ['Sedan', 'Hotel 2N', 'Driver', 'Local drops'],
                'exclusions' => ['Boat ride', 'Temple VIP darshan'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Reach Varanasi', 'detail' => 'Ganga Aarti at night.'],
                    ['day' => 'Day 2', 'title' => 'Temples & Sarnath', 'detail' => 'Spiritual circuit.'],
                    ['day' => 'Day 3', 'title' => 'Return Saharsa', 'detail' => 'Morning departure.'],
                ],
            ],
            [
                'title' => 'Patna Local Sightseeing',
                'destination' => 'Patna',
                'region' => 'Bihar',
                'category' => 'SIGHTSEEING',
                'price_paise' => 349900,
                'nights' => 0,
                'km' => 180,
                'vehicle' => 'SEDAN',
                'min_pax' => 2,
                'popular' => false,
                'places' => 'Golghar, Gandhi Maidan, Patna Sahib, Zoo',
                'gallery' => [
                    'https://images.unsplash.com/photo-1524492412937-b28074a5d7da?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1587474260584-136574528ed5?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['Same-day city tour', 'Patna Sahib', 'Comfort sedan'],
                'inclusions' => ['12-hour sedan', 'Driver', 'Fuel & toll'],
                'exclusions' => ['Entry tickets', 'Meals'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Full-day Patna', 'detail' => 'Pickup Saharsa / Patna hotel, 8–10 city stops, drop.'],
                ],
            ],
            [
                'title' => 'Corporate Offsite — Rajgir & Nalanda',
                'destination' => 'Rajgir',
                'region' => 'Bihar',
                'category' => 'CORPORATE',
                'price_paise' => 899900,
                'nights' => 1,
                'km' => 360,
                'vehicle' => 'TRAVELLER',
                'min_pax' => 8,
                'popular' => false,
                'places' => 'Nalanda University ruins, Vishwa Shanti Stupa, Ropeway',
                'gallery' => [
                    'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1524492412937-b28074a5d7da?auto=format&fit=crop&w=1400&q=80',
                ],
                'highlights' => ['12-seater Traveller', 'Team offsite ready', 'Hotel + sightseeing'],
                'inclusions' => ['Tempo Traveller', 'Hotel 1N', 'Driver night halt', 'Local guide on request'],
                'exclusions' => ['Ropeway tickets', 'Team meals'],
                'itinerary' => [
                    ['day' => 'Day 1', 'title' => 'Nalanda & Rajgir', 'detail' => 'Ruins, lunch stop, hotel.'],
                    ['day' => 'Day 2', 'title' => 'Peace Pagoda & return', 'detail' => 'Ropeway optional, drop Saharsa.'],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function corporatePlans(): array
    {
        return [
            [
                'plan_key' => 'ON_DEMAND',
                'title' => 'On-Demand Executive',
                'subtitle' => 'Book a sedan or SUV whenever the meeting pops up',
                'pricing_mode' => 'VEHICLE',
                'price_paise' => 129900,
                'price_label' => 'From ₹1,299 / trip',
                'gst_percent' => 5,
                'highlights' => json_encode([
                    'Instant cab for airport & client visits',
                    'GST invoice on every trip',
                    'Live tracking for the office',
                    'Pay per ride — no lock-in',
                ]),
                'sort_order' => 1,
                'status' => 'PUBLISHED',
            ],
            [
                'plan_key' => 'MONTHLY',
                'title' => 'Monthly Desk Plan',
                'subtitle' => 'Fixed monthly billing for regular office travel',
                'pricing_mode' => 'FIXED',
                'price_paise' => 2499900,
                'price_label' => '₹24,999 / month',
                'gst_percent' => 5,
                'highlights' => json_encode([
                    'Up to 1,500 km included',
                    'Priority 30-min SLA in city',
                    'Dedicated relationship manager',
                    'Consolidated GST invoice',
                ]),
                'sort_order' => 2,
                'status' => 'PUBLISHED',
            ],
            [
                'plan_key' => 'EMPLOYEE',
                'title' => 'Employee Shuttle',
                'subtitle' => 'Daily pickup & drop for your team',
                'pricing_mode' => 'QUOTE',
                'price_paise' => 0,
                'price_label' => 'Custom Get Quote',
                'gst_percent' => 5,
                'highlights' => json_encode([
                    'Home-to-office routes',
                    'Multiple stops on one vehicle',
                    'Attendance-friendly reports',
                    'Sedan / Traveller mix',
                ]),
                'sort_order' => 3,
                'status' => 'PUBLISHED',
            ],
            [
                'plan_key' => 'EVENT_BULK',
                'title' => 'Events & Conferences',
                'subtitle' => 'Fleet for offsites, training and guest pickup',
                'pricing_mode' => 'QUOTE',
                'price_paise' => 0,
                'price_label' => 'Custom Get Quote',
                'gst_percent' => 5,
                'highlights' => json_encode([
                    'Airport arrivals desk',
                    'Mixed fleet: sedan, SUV, Traveller',
                    'On-ground coordinator',
                    '24×7 ops chat',
                ]),
                'sort_order' => 4,
                'status' => 'PUBLISHED',
            ],
            [
                'plan_key' => 'AIRPORT_DESK',
                'title' => 'Airport Desk',
                'subtitle' => 'Meet-and-greet for directors and guests',
                'pricing_mode' => 'VEHICLE',
                'price_paise' => 189900,
                'price_label' => 'From ₹1,899',
                'gst_percent' => 5,
                'highlights' => json_encode([
                    'Name-board pickup',
                    'Flight tracking buffer',
                    'Premium sedan / Innova',
                    'GST billing to company',
                ]),
                'sort_order' => 5,
                'status' => 'PUBLISHED',
            ],
            [
                'plan_key' => 'RETAINER',
                'title' => 'Annual Retainer',
                'subtitle' => 'Best rates when your company travels every week',
                'pricing_mode' => 'FIXED',
                'price_paise' => 9999900,
                'price_label' => '₹99,999 / quarter',
                'gst_percent' => 5,
                'highlights' => json_encode([
                    'Locked corporate tariff',
                    'Credit cycle 15–30 days',
                    'Policy & approval workflow',
                    'Quarterly travel report',
                ]),
                'sort_order' => 6,
                'status' => 'PUBLISHED',
            ],
        ];
    }
}
