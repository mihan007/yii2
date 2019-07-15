## Проект "autoscan.online"
Пример кода на Yii2, который:
- реализует парсинг сайтов СНГ с минимальной задержкой (используется
RabbitMq с корректными настройками - теми, которые дают возможность
разным скриптам писать в разные очереди)
- система построена на демонах PHP(как бы это ни странно казалось)
под руководством supervisor (т.е поддерживается самостоятельное
восстановление системы после failover)
- система поддерживает обход капч на сайтах (их ставят для защиты
части информации). Обход капч происходит в нескольких режимах:
1. php функция, которая распознает простые капчи (обычный текст как
картинка). Реализовано через php библиотеку компьютерного
зрения [Tesseract](https://ru.wikipedia.org/wiki/Tesseract)
2. скрипт на node.js, который через Chrome Puppeteer забирает
сложные капчи (например, Гугл Рекапчу) и отправляет их на сервис
распознавания капч, а полученный ответ вставляет на сайт. Использование
headless chrome обусловленно тем, что на сайте используется хитрая
система подписи формы капчи и ее реверс инжениринг был оценен
сложнее чем инициализация хрома
3. Простая капча(кривонаписання картинка) - скрипт на php отправляет
ее на сервис распознавания капч, полученный ответ сабмитит на сайт

На проекте много других интересных моментов реализовано и можно
показать (как подписки на обновления по определенному набору
параметров, синхронизация лэндинга с Тильдой по апи). Эту информацию могу предоставить по запросу.

Сложность была в том, что парсинг данных был для меня ранее
лишь небольшой частью работы, тут же пришлось занятся вплотную
и получилось со второго раза. Первый раз понадеялся на [Spatie Crawler](https://github.com/spatie/crawler),
т.к. не сторонник изобретать велосипеды. Но эта библиотека не подошла
нам, т.к. тормозила и работала в целом не очень стабильно (там использовался
curt_multi_exec, поверх которого бежала еще одна абстракция и в
целом это давало ощутимое latency, которое не удовлетворяло поставленной задача, а
именно публикации объявления у нас в течении нескольких секунд
после появления объявления на сайте доноре).

На данный момент проект переходит из стадии "а давайте просто спарсим на
этап "надо договориться со всеми сайтами и работать через их апи", т.к.
идея показала право на жизнь и к сайтам мы придем уже не с голой презентацией
оторванной от жизни, а с реальными экономическими показателями.