<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no')->unique();
            $table->string('name');
            $table->string('client_type')->default('Foreign');
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('gstin')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('currency', 3);
            $table->string('account_no')->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->text('beneficiary_address')->nullable();
            $table->text('bank_address')->nullable();
            $table->string('swift')->nullable();
            $table->string('ifsc')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type')->default('proforma');
            $table->string('number')->nullable()->unique();
            $table->foreignId('proforma_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3);
            $table->string('status')->default('Draft');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('inr_rate', 14, 6)->nullable();
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('irn', 64)->nullable()->unique();
            $table->string('irn_ack_no')->nullable();
            $table->dateTime('irn_ack_date')->nullable();
            $table->text('irn_signed_qr')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 500);
            $table->string('sac', 10)->default('998213');
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('rate', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->boolean('taxable')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->date('received_on');
            $table->decimal('amount', 14, 2);
            $table->decimal('tds', 14, 2)->default(0);
            $table->string('reference');
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series');
            $table->string('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['series', 'year']);
        });
    }

    public function down(): void
    {
        foreach (['number_sequences', 'payments', 'invoice_items', 'invoices', 'bank_accounts', 'clients', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
