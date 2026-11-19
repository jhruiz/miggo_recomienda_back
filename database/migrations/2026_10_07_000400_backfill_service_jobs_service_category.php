<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillServiceCategory();
    }

    /**
     * Fills service_jobs.service_category_id for jobs that have none. Idempotent, UPDATE only.
     *
     * (a) Jobs born from a quote request take the request's category.
     * (b) Direct jobs (quote without request) take the provider's profile category
     *     only when the provider has exactly one; every other job stays null.
     *
     * Uses correlated subqueries (no UPDATE ... JOIN) so it runs on MySQL and SQLite.
     */
    public function backfillServiceCategory(): void
    {
        $requestCategorySql = '(select quote_requests.service_category_id from quotes'
            .' inner join quote_requests on quote_requests.id = quotes.quote_request_id'
            .' where quotes.id = service_jobs.quote_id)';

        DB::table('service_jobs')
            ->whereNull('service_category_id')
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('quotes')
                    ->join('quote_requests', 'quote_requests.id', '=', 'quotes.quote_request_id')
                    ->whereColumn('quotes.id', 'service_jobs.quote_id');
            })
            ->update(['service_category_id' => DB::raw($requestCategorySql)]);

        $providerCategoriesFromSql = ' from provider_categories'
            .' inner join provider_profiles on provider_profiles.id = provider_categories.provider_profile_id'
            .' where provider_profiles.user_id = service_jobs.provider_user_id';

        DB::table('service_jobs')
            ->whereNull('service_category_id')
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('quotes')
                    ->whereColumn('quotes.id', 'service_jobs.quote_id')
                    ->whereNull('quotes.quote_request_id');
            })
            ->whereRaw('(select count(*)'.$providerCategoriesFromSql.') = 1')
            ->update([
                'service_category_id' => DB::raw('(select min(provider_categories.service_category_id)'.$providerCategoriesFromSql.')'),
            ]);
    }

    public function down(): void
    {
        // Backfilled values are kept; the column is dropped by the add_service_category migration.
    }
};
