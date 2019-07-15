curl -vs -X POST \
  https://adwords.google.com/api/adwords/reportdownload/v201806 \
  -H 'Authorization: Bearer ya29.Glu7BkaT5VZ2-YmY4fXT9gTXcFI-dLCojsxR9KUT0PJA34vkSxV62jO77TWC2Q2BE68XWYEsDwtCkjzUQwFCvPEJbed2eNSR9YV23d5UsIBfVYl3BuT3sFCQ9iTH' \
  -H 'developertoken: developertoken' \
  -H 'clientcustomerid: 801-426-1966' \
  -H 'skipcolumnheader: false' \
  -H 'skipreportheader: false' \
  -H 'skipreportsummary: false' \
  -H 'useRawEnumValues: false' \
  -d '__rdquery=SELECT+AdGroupId%2C+AdGroupName%2C+Id%2C+AdNetworkType2%2C+CampaignId%2C+CampaignName%2C+Clicks%2C+Cost%2C+Date%2C+Device%2C+Impressions+FROM+AD_PERFORMANCE_REPORT+DURING+20190224%2C20190224&__fmt=TSV'
  2>&1 | sed '/^* /d; /bytes data]$/d; s/> //; s/< //'