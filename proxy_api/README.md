## Проект "Proxy API"
Для одного из клиентов - рекламного агенства - решал следующую задачу:
- имеется набор рекламных сетей, куда рекламодатель размещает объявления
- необходимо предоставить единую точку входа, куда пользователь будет обращаться за 
различным данными из этих рекламных сетей
- у пользователя нет ключей к апи сетей, подключение напряму к ним для пользователя не предоставляется
- необходимо проксировать часть методов (с изменением ответа - в частности на цену клика, условно, нужно
домножать маржу рекламного агенства)

Сложность была в том, чтобы нивелировать различия апи рекламных
площадок, также в процессе работы были доработаны php и java
библиотеки Гугл Эдвордс (они используют соап в работе и эндпоинты
были жестно зашиты в код). На выходе клиент получил те же самые 
библиотеки, но работали они уже через наш прокси апи.

На данный момент проект успешно функционирует и нуждается в доработках
лишь тогда, когда рекламные площадки "ломают" обратную совместимость. Это
происходит крайне редко.