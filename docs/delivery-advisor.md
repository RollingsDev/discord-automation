# 🏍️ Entregas SP — Zona Norte

Boletim meteorológico focado em entregadores que atuam na Zona Norte de São Paulo.

## Secret

`WEBHOOK_DELIVERY`

## Regiões representativas

- Santana
- Tucuruvi
- Vila Maria
- Casa Verde

O script consulta previsão atual e das próximas 4 horas para essas regiões e calcula um índice operacional meteorológico de 0 a 100.

Ele considera:

- probabilidade e volume de chuva;
- tempestades;
- rajadas de vento;
- sensação térmica;
- umidade.

## Horários

- 10:35
- 16:35
- 19:35

Horário de São Paulo.

O índice **não estima demanda, ganhos, tarifa dinâmica ou volume de pedidos**. Ele serve apenas como resumo das condições meteorológicas para quem pretende rodar de moto ou bicicleta.

O estado fica em `.state/delivery-advisor.json`. O workflow manual permite forçar uma nova publicação.
