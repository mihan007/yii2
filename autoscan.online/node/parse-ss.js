require('dotenv').config();
const {AntiCaptcha} = require('anticaptcha');
const {RuCaptcha} = require('./ruCaptcha');
const {Cluster} = require('puppeteer-cluster');
const mysql = require('mysql');
const _ = require('lodash');

const VIN_CRAWL_FAILED = 'failed';
const VIN_IS_EMPTY = 'empty';

const RETRY_LIMIT = 3;
const RETRY_DELAY = 5000;

const db = mysql.createConnection({
  host: process.env.MYSQL_HOST,
  user: process.env.MYSQL_USER,
  password: process.env.MYSQL_PASSWORD,
  database: process.env.MYSQL_DB,
});
db.connect();
const dbQuery = (query, ...values) => new Promise((resolve, reject) => {
  db.query(query, values, (err, result, fields) => {
    if (err) {
      reject(err);
    } else {
      resolve(result);
    }
  });
});

const dbQueryOne = async (query, ...values) => {
  const result = await dbQuery(query, ...values);
  if (!result || result.length === 0) {
    return undefined;
  }
  return result[0];
};

const log = (value) => {
  console.log(`${new Date().toISOString()}:`, value);
};

const error = (value, stack = '') => {
  console.error(`${new Date().toISOString()}`, value, stack);
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const AntiCaptchaAPI = new AntiCaptcha(process.env.ANTICAPTCHA_KEY);
const rc = new RuCaptcha({
  key: process.env.RUCAPTCHA_KEY, // Required: Your api key.
  debug: false, // Not required|Default: false
  delay: 3000,
});

async function resolveGoogleCaptcha(url) {
  log(`Start to solve google captcha for ${url}`);
  const websiteGCaptchaKey = process.env.SS_GCAPTCHA_KEY;
  try {
    return rc.google(websiteGCaptchaKey, url);
  } catch (e) {
    error(e, e.stack);
    const taskId = await AntiCaptchaAPI.createTask(url, websiteGCaptchaKey);
    return await AntiCaptchaAPI.getTaskResult(taskId);
  }
}

async function resolveImageCaptcha(base64image) {
  return rc.image(base64image);
}

async function pageHasElement(page, selector) {
  return page.$$eval(selector, modals => modals.length > 0);
}

async function getElementText(page, selector) {
  return page.$$eval(selector, elems => elems.length > 0 ? elems[0].innerText : null);
}

async function showVinAndRegNum(page, url) {
  const hasVinShowButton = await pageHasElement(page, '#tdo_1678 a');
  if (!hasVinShowButton) {
    return;
  }
  await page.evaluate(() => {
    document.querySelector('#tdo_1678 a').click();
  });
  await page.waitForFunction(
    `!!document.querySelector('.alert_dv') || document.querySelector('#tdo_1678').children.length === 0`,
    {timeout: 5000},
  );
  if (await pageHasElement(page, '.alert_dv')) {
    const captcha = await resolveGoogleCaptcha(url);
    await page.evaluate((cpt) => {
      _show_js_special_data(
        document.getElementById("recaptcha_html_element").getAttribute("data"),
        'ru',
        cpt,
      );
      document.querySelector('.alert_dv').remove();
    }, captcha);
    await page.waitForFunction(
      `document.querySelector('#tdo_1678').children.length === 0`,
      {timeout: 5000},
    );
  }
}

async function showPhone(page, url) {
  const hasPhoneField = await pageHasElement(page, '#ph_td_1');
  if (!hasPhoneField) {
    return;
  }
  const hasPhoneShowNumber = await pageHasElement(page, '#phdivz_1 a');
  if (hasPhoneShowNumber) {
    await page.evaluate(() => {
      document.querySelector('#phdivz_1 a').click();
    });
  }
  await page.waitForFunction(
    `!!document.querySelector('.alert_dv') || !document.querySelector('#ph_td_1').innerText.includes('*')`,
    {timeout: 5000},
  );
  if (await pageHasElement(page, '.alert_dv')) {
    if (await pageHasElement(page, '#ss_tcode_img')) {
      await sleep(1000);
      const image = await page.$('#ss_tcode_img');
      const captchaImage = await image.screenshot({encoding: 'base64'});
      log(`Start to solve image captcha for ${url}`);
      const captchaResult = await resolveImageCaptcha(captchaImage);
      await page.type('#ads_show_phone', captchaResult);
      await page.evaluate(() => {
        document.querySelector('.alert_body .btn').click();
      });
    } else {
      const captcha = await resolveGoogleCaptcha(url);
      await page.evaluate((cpt) => {
        _show_phone_captcha(cpt);
        document.querySelector('.alert_dv').remove();
      }, captcha);
    }
    await page.waitForFunction(
      `!document.querySelector('#ph_td_1').innerText.includes('*')`,
      {timeout: 6000},
    );
  }
}

async function parsePageData(page, url) {
  // page.on('console', consoleObj => console.log(consoleObj.text())); // Debug internal console
  await page.goto(url, {timeout: 15000});

  await showVinAndRegNum(page, url);
  await showPhone(page, url);

  const vin = await getElementText(page, '#tdo_1678');
  const regNum = await getElementText(page, '#tdo_1714');
  const phone1 = await getElementText(page, '#phone_td_1');
  const phone2 = await getElementText(page, '#phone_td_2');
  const phones = [phone1, phone2].filter((p) => !!p);
  if (phones.filter(str => str.includes('*')).length > 0) {
    throw new Error('Phone not recognized');
  }
  return {
    vin,
    regNum,
    phone: phones,
  };
}

async function addTasksToQueue(cluster, chunkSize = 20) {
  log('Add new tasks to queue');

  // language=MySQL
  const data = await dbQuery(`
      SELECT id, first_url
      FROM car
      WHERE vin IS NULL
        AND first_url LIKE 'https://www.ss.com/%'
      ORDER BY created_at DESC
      LIMIT ?
  `, chunkSize);
  for (const {first_url} of data) {
    await cluster.queue(first_url);
  }
  return data.length;
}

async function updateCarDataByUrl(url, {phone, vin, regNum}) {
  const phones = phone && phone.length > 0 ? phone.join(', ') : null;
  log(`Write data for ${url} : phone=${phones} ; vin=${vin} ; reg_number=${regNum}`);
  // language=MySQL
  await dbQuery(
    'UPDATE car SET vin = ?, reg_number = ?, first_seller_phone = ? WHERE first_url = ?',
    vin || VIN_IS_EMPTY,
    regNum,
    phones,
    url,
  );
  if (phones) {
    // language=MySQL
    const {first_seller_id} = await dbQueryOne('SELECT first_seller_id FROM car WHERE first_url = ?', url);
    // language=MySQL
    await dbQuery(
      'UPDATE car_seller SET phone = ? WHERE id = ?',
      phones,
      first_seller_id,
    );
    if (phone && phone.length > 0) {
      const getClearPhone = str => str.replace(/[\(\)\s\-]/g, '');
      const clearedNumbers = phone.map(getClearPhone);
      // language=MySQL
      const storedPhones = await dbQuery(
          `SELECT id, phone
           FROM car_seller_phone
           WHERE car_seller_id = ?`,
        first_seller_id,
      );
      const storedNumbers = _.map(storedPhones, 'phone');
      const newNumbers = _.difference(clearedNumbers, storedNumbers);
      for (const newNumber of newNumbers) {
        // language=MySQL
        await dbQuery(`
          INSERT INTO car_seller_phone (car_seller_id, phone, created_at) 
          VALUE (?, ?, now())
        `, first_seller_id, newNumber);
      }
    }
  }
}

(async () => {
  const cluster = await Cluster.launch({
    concurrency: Cluster.CONCURRENCY_PAGE,
    maxConcurrency: process.env.SS_PUPPETEER_CONCURRENCY_LIMIT || 4,
    puppeteerOptions: {
      args: ['--no-sandbox'],
      timeout: 10 * 1000,
    },
    workerCreationDelay: 1000,
    timeout: 10 * 60 * 1000, // С учетом суммы повторных попыток
  });

  process.once('SIGTERM', async () => {
    await cluster.close();
    process.exit();
  });

  await cluster.task(async ({page, data: url}) => {
    let tries = 0;
    while (tries <= RETRY_LIMIT) {
      try {
        log(`Start crawling ${url}${tries > 0 ? ' (Retry = ' + tries + ')' : ''}`);
        const result = await parsePageData(page, url);
        await updateCarDataByUrl(url, result);
        break;
      } catch (err) {
        tries++;
        if (err.message.includes('waiting for function failed')) {
          error(`Error crawling ${url}: ${err.message}`);
        } else {
          error(`Error crawling ${url}: ${err.message}`, err.stack);
        }
        await sleep(RETRY_DELAY);
      }
    }
    if (tries > RETRY_LIMIT) {
      log(`Retries limit exceeded, reject url ${url}`);
      await updateCarDataByUrl(url, {vin: VIN_CRAWL_FAILED});
    } else {
      log(`Successful crawled ${url}`);
    }
  });

  cluster.on('taskerror', (err, data) => {
    error(`Error in task ${data}: ${err.message}`, err.stack);
  });

  while (true) {
    await cluster.idle();
    const added = await addTasksToQueue(cluster, 100);
    if (added === 0) {
      await sleep(5 * 60 * 1000);
    }
  }
})();
