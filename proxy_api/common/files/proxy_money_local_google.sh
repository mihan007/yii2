curl -X POST \
  http://api.proxy_api.lcl/v1/api/adwords/reportdownload/v201806 \
  -H 'Accept: text/html, image/gif, image/jpeg, *; q=.2, */*; q=.2' \
  -H 'Accept-Encoding: gzip' \
  -H 'Authorization: Fv-yBsUyfxAsK-quxrkiug5RNMJYIajbAXfTW_u526uwQF44' \
  -H 'Connection: keep-alive' \
  -H 'Content-Length: 210' \
  -H 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' \
  -H 'User-Agent: unknown (AwApi-Java, AdWords-Axis/4.4.0, Common-Java/4.4.0, Axis/1.4, Java/1.8.0_181, maven, ReportDownloader, ReportQueryBuilder) Google-HTTP-Java-Client/1.23.0 (gzip)' \
  -H 'cache-control: no-cache' \
  -H 'clientcustomerid: %CLIENT_CUSTOMER_ID%' \
  -H 'developertoken: %DEVELOPER_TOKEN%' \
  -H 'includezeroimpressions: true' \
  -H 'platform: google' \
  -H 'skipcolumnheader: false' \
  -H 'skipreportheader: false' \
  -H 'skipreportsummary: true' \
  -d '__rdquery=SELECT+AdGroupId%2C+AdGroupName%2C+Id%2C+AdNetworkType2%2C+CampaignId%2C+CampaignName%2C+Clicks%2C+Cost%2C+Date%2C+Device%2C+Impressions+FROM+AD_PERFORMANCE_REPORT+DURING+20190224%2C20190224&__fmt=TSV'