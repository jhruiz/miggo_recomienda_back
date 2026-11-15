@echo off
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" vendor\bin\phpunit --filter "QuoteShowEnrichmentTest|QuoteFlowPreservationTest|QuoteTest|QuoteAcceptRejectTest|DirectQuoteTest|MyQuotesListingTest" > run53.log 2>&1
