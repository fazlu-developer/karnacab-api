<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_designations')) {
            Schema::create('staff_designations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->unsignedSmallInteger('sort_order')->default(10);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('staff_letterhead')) {
            Schema::create('staff_letterhead', function (Blueprint $table) {
                $table->id();
                $table->string('legal_name', 180);
                $table->string('brand_name', 80)->default('KarnaRide');
                $table->string('cin', 32)->nullable();
                $table->string('gstin', 20)->nullable();
                $table->string('office_address', 400);
                $table->string('signatory_name', 120);
                $table->string('signatory_title', 80)->default('Director');
                $table->string('powered_by', 80)->default('Joddo Corp');
                $table->string('signature_path', 180)->nullable();
                $table->string('coi_path', 180)->nullable();
                $table->string('gst_path', 180)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('staff_joinings')) {
            Schema::create('staff_joinings', function (Blueprint $table) {
                $table->id();
                $table->string('public_ref', 24)->nullable()->unique();
                $table->string('full_name', 180);
                $table->string('phone', 20);
                $table->string('email', 180)->nullable();
                $table->unsignedBigInteger('designation_id');
                $table->string('posting', 160);
                $table->string('address', 300)->nullable();
                $table->date('joining_on');
                $table->string('letter_type', 16);
                $table->unsignedInteger('offered_rupees')->nullable();
                $table->string('reporting_to', 160)->nullable();
                $table->string('document_hash', 64)->nullable();
                $table->timestamps();
            });
        }

        if (DB::table('staff_designations')->count() === 0) {
            $now = now();
            $names = ['Director', 'State Head', 'District Head', 'Fleet Manager', 'Operations Executive', 'Accounts Executive', 'Customer Support'];
            foreach ($names as $i => $name) {
                DB::table('staff_designations')->insert([
                    'name' => $name,
                    'sort_order' => ($i + 1) * 10,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (DB::table('staff_letterhead')->count() === 0) {
            $now = now();
            DB::table('staff_letterhead')->insert([
                'legal_name' => 'KarnaRide',
                'brand_name' => 'KarnaRide',
                'cin' => 'U52291BR2024PTC072278',
                'gstin' => null,
                'office_address' => 'Ward no. 6/41, C/O Baleshwar Prasad, Refugee Colony, Kahara, Saharsa, Bihar 852202',
                'signatory_name' => 'Director',
                'signatory_title' => 'Director',
                'powered_by' => 'Joddo Corp',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_joinings');
        Schema::dropIfExists('staff_letterhead');
        Schema::dropIfExists('staff_designations');
    }
};
