const axios = require('axios');

module.exports.RuCaptcha = class RuCaptcha {

  constructor(settings) {

    const checkKey = () => {
      if (!this.key)
        throw new Error('Api key is required');
    };

    this.key = null;
    this.in = 'http://rucaptcha.com/in.php';
    this.res = 'http://rucaptcha.com/res.php';
    this.delay = 1000;
    this.debug = false;

    // user can override in,res,delay, etc.
    Object.keys(settings).forEach(key => {
      this[key] = settings[key];
    });

    checkKey();

    /**
     * Solve google recaptcha.
     *
     * @param googlekey
     * @param pageurl
     * @return {Promise<string>}
     */
    this.google = async (googlekey, pageurl) => {
      const id = await getId({
        googlekey,
        pageurl,
        method: 'userrecaptcha',
      });

      return await waitResponse(id);
    };

    /**
     * Solve image recaptcha.
     *
     * @param imageBase64
     * @return {Promise<string>}
     */
    this.image = async (imageBase64) => {
      const id = await getId({
        body: imageBase64,
        method: 'base64',
      });

      return await waitResponse(id);
    };

    /**
     * Get captcha id from ruCaptcha
     *
     * @param params
     * @return {Promise<void>}
     */
    const getId = async params => {
      const data = await call(this.in, params);

      if (data.hasOwnProperty('status') && data.status === 1) {
        return data.request;
      }

      throw new Error(data.request);
    };

    /**
     * Ajax call with parameters.
     *
     * @param url
     * @param params
     * @return {Promise<any>}
     */
    const call = async (url, params) => {

      if (this.debug)
        console.log(`Fetch: ${url}`);

      return new Promise((resolve) => {
        axios.get(url, {
          params: Object.assign({key: this.key, json: 1}, params),
        }).then(({data}) => resolve(data))
          .catch(err => {
            throw new Error(err.toString());
          });
      });
    };

    /**
     * Wait while captcha guessing
     *
     * @param id
     * @return {Promise<string>}
     */
    const waitResponse = async id => {

      let isSolved = false,
        delay = async (ms) => {
          return new Promise(res => setTimeout(res, ms));
        };

      while (!isSolved) {
        await delay(this.delay);

        const response = await call(this.res, {
            id,
            action: 'get',
          }), status = response.status,
          request = response.request;

        if (this.debug)
          console.log(request);

        if (status === 1) {
          return request;
        }

        if (status === 0 && request === 'CAPCHA_NOT_READY')
          continue;

        if (status === 0) {
          throw new Error(request);
        }
      }
    };
  }

  /**
   * Create instance.
   *
   * @param settings
   * @return {RuCaptcha}
   */
  static create(settings = {}) {
    return (new RuCaptcha(settings));
  }
}
