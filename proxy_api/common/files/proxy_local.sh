#!/bin/bash
curl -vs -X POST \
  http://api.proxy_api.lcl/v1/api/adwords/cm/v201809/CampaignService \
  -H 'Accept: application/soap+xml, application/dime, multipart/related, text/*' \
  -H 'Authorization: Fv-yBsUyfxAsK-quxrkiug5RNMJYIajbAXfTW_u526uwQF44' \
  -H 'Platform: google' \
  -H 'Cache-Control: no-cache' \
  -H 'Cloudfront-Forwarded-Proto: https' \
  -H 'Cloudfront-Is-Desktop-Viewer: true' \
  -H 'Cloudfront-Is-Mobile-Viewer: false' \
  -H 'Cloudfront-Is-Smarttv-Viewer: false' \
  -H 'Cloudfront-Is-Tablet-Viewer: false' \
  -H 'Cloudfront-Viewer-Country: RU' \
  -H 'Connect-Time: 1' \
  -H 'Connection: close' \
  -H 'Content-Type: text/xml; charset=utf-8' \
  -H 'Pragma: no-cache' \
  -H 'Soapaction: ""' \
  -H 'Total-Route-Time: 0' \
  -H 'User-Agent: Axis/1.4' \
  -H 'Via: 1.0 8b5bc0831e6dab612582614c3009efa7.cloudfront.net (CloudFront), 1.1 vegur' \
  -H 'X-Amz-Cf-Id: soE2NmJb1BugovrVSNFewPR6YhJ6kVwC2rMC6cI9n0Lq4QMOY62t9A==' \
  -H 'X-Request-Id: 664adb18-d828-4f92-a9f9-3721b1bccf43' \
  -H 'cache-control: no-cache' \
  -d '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soapenv:Header><ns1:RequestHeader soapenv:mustUnderstand="0" xmlns:ns1="https://adwords.google.com/api/adwords/cm/v201809"><ns1:clientCustomerId>%CLIENT_CUSTOMER_ID%</ns1:clientCustomerId><ns1:developerToken>%DEVELOPER_TOKEN%</ns1:developerToken><ns1:userAgent>unknown (AwApi-Java, AdWords-Axis/4.4.0, Common-Java/4.4.0, Axis/1.4, Java/1.8.0_181, maven, SelectorBuilder, SelectorField)</ns1:userAgent><ns1:validateOnly>false</ns1:validateOnly><ns1:partialFailure>false</ns1:partialFailure></ns1:RequestHeader></soapenv:Header><soapenv:Body><get xmlns="https://adwords.google.com/api/adwords/cm/v201809"><serviceSelector><fields>Id</fields><fields>Name</fields><ordering><field>Name</field><sortOrder>ASCENDING</sortOrder></ordering><paging><startIndex>0</startIndex><numberResults>100</numberResults></paging></serviceSelector></get></soapenv:Body></soapenv:Envelope>
' 2>&1 | sed '/^* /d; /bytes data]$/d; s/> //; s/< //'