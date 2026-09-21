import { sendDiscord } from "./discord.js";
import { TIMEZONE, formatDateBr, formatHour, weekdayPt } from "./time.js";

const API_URL = "https://api.open-meteo.com/v1/forecast";
const LATITUDE = -23.5505;
const LONGITUDE = -46.6333;

export async function publishHourlyWeather(env) {
  const data = await weatherData();
  const current = object(data.current);
  const hourly = object(data.hourly);
  const hourIndex = currentHourlyIndex(hourly);
  const code = Number(current.weather_code ?? 0);
  const [icon, condition] = weatherInfo(code);
  const alerts = automaticAlerts(data, hourIndex);
  const hourProb = hourly.precipitation_probability?.[hourIndex];

  await sendDiscord(env.DISCORD_CLIMA_WEBHOOK, {
    embeds: [
      {
        title: `${icon} Clima em São Paulo — ${new Intl.DateTimeFormat("pt-BR", {
          timeZone: TIMEZONE,
          hour: "2-digit",
          minute: "2-digit",
          hourCycle: "h23"
        }).format(new Date())}`,
        description: `**${condition}**\nSão Paulo, SP`,
        color: embedColor(code),
        fields: [
          {
            name: "🌡️ Agora",
            value:
              `**${n(current.temperature_2m)}°C**\n` +
              `Sensação: ${n(current.apparent_temperature)}°C\n` +
              `Umidade: ${pct(current.relative_humidity_2m)} • Nuvens: ${pct(current.cloud_cover)}`,
            inline: true
          },
          {
            name: "🌧️ Chuva",
            value:
              `Agora: ${n(current.precipitation)} mm\n` +
              `Probabilidade: ${pct(hourProb)}`,
            inline: true
          },
          {
            name: "💨 Vento",
            value:
              `${n(current.wind_speed_10m)} km/h\n` +
              `Rajadas: ${n(current.wind_gusts_10m)} km/h`,
            inline: true
          },
          {
            name: "🕒 Próximas 3 horas",
            value: nextHoursText(data, hourIndex, 3),
            inline: false
          },
          {
            name: "🔔 Notificação",
            value:
              alerts.length === 0
                ? "✅ **Sem aviso automático no momento.**"
                : alerts.join("\n"),
            inline: false
          }
        ],
        footer: {
          text: "Dados: Open-Meteo • Avisos automáticos não são alertas oficiais"
        },
        timestamp: new Date().toISOString()
      }
    ]
  });
}

export async function publishDailyWeather(env) {
  const data = await weatherData();
  const daily = object(data.daily);
  const dates = Array.isArray(daily.time) ? daily.time : [];
  const codes = Array.isArray(daily.weather_code) ? daily.weather_code : [];

  if (!dates.length) {
    throw new Error("Open-Meteo não retornou previsão diária.");
  }

  const todayCode = Number(codes[0] ?? 0);
  const [todayIcon, todayCondition] = weatherInfo(todayCode);
  const warnings = dailyAlerts(data);
  const nextDays = [];

  for (let index = 1; index < Math.min(4, dates.length); index++) {
    const [dayIcon, dayCondition] = weatherInfo(Number(codes[index] ?? 0));

    nextDays.push(
      `${dayIcon} **${weekdayPt(dates[index])} (${formatDateBr(dates[index])})** — ` +
      `${n(daily.temperature_2m_min?.[index], 0)}° / ` +
      `${n(daily.temperature_2m_max?.[index], 0)}°C • chuva ` +
      `${pct(daily.precipitation_probability_max?.[index])} • ${dayCondition}`
    );
  }

  await sendDiscord(env.DISCORD_CLIMA_WEBHOOK, {
    embeds: [
      {
        title: `${todayIcon} Previsão do Dia — São Paulo`,
        description:
          `**${weekdayPt(dates[0])}, ${formatDateBr(dates[0])}**\n` +
          todayCondition,
        color: embedColor(todayCode),
        fields: [
          {
            name: "🌡️ Temperaturas",
            value:
              `Mínima: **${n(daily.temperature_2m_min?.[0])}°C**\n` +
              `Máxima: **${n(daily.temperature_2m_max?.[0])}°C**\n` +
              `Sensação: ${n(daily.apparent_temperature_min?.[0])}° a ` +
              `${n(daily.apparent_temperature_max?.[0])}°C`,
            inline: true
          },
          {
            name: "🌧️ Chuva",
            value:
              `Chance máxima: **${pct(daily.precipitation_probability_max?.[0])}**\n` +
              `Volume previsto: **${n(daily.precipitation_sum?.[0])} mm**`,
            inline: true
          },
          {
            name: "💨 Vento",
            value:
              `Máximo: ${n(daily.wind_speed_10m_max?.[0])} km/h\n` +
              `Rajadas: ${n(daily.wind_gusts_10m_max?.[0])} km/h`,
            inline: true
          },
          {
            name: "🌅 Sol",
            value:
              `Nascer: **${formatHour(daily.sunrise?.[0])}**\n` +
              `Pôr: **${formatHour(daily.sunset?.[0])}**`,
            inline: true
          },
          {
            name: "🔔 Atenção hoje",
            value:
              warnings.length === 0
                ? "✅ **Sem aviso automático relevante para hoje.**"
                : warnings.join("\n"),
            inline: false
          },
          {
            name: "📅 Próximos dias",
            value: nextDays.length ? nextDays.join("\n") : "Sem dados.",
            inline: false
          }
        ],
        footer: {
          text: "Dados: Open-Meteo • São Paulo, SP"
        },
        timestamp: new Date().toISOString()
      }
    ]
  });
}

async function weatherData() {
  const params = new URLSearchParams({
    latitude: String(LATITUDE),
    longitude: String(LONGITUDE),
    timezone: TIMEZONE,
    forecast_days: "4",
    current: [
      "temperature_2m",
      "apparent_temperature",
      "relative_humidity_2m",
      "precipitation",
      "rain",
      "weather_code",
      "cloud_cover",
      "wind_speed_10m",
      "wind_gusts_10m",
      "is_day"
    ].join(","),
    hourly: [
      "temperature_2m",
      "apparent_temperature",
      "relative_humidity_2m",
      "precipitation_probability",
      "precipitation",
      "weather_code",
      "wind_speed_10m",
      "wind_gusts_10m"
    ].join(","),
    daily: [
      "weather_code",
      "temperature_2m_max",
      "temperature_2m_min",
      "apparent_temperature_max",
      "apparent_temperature_min",
      "precipitation_sum",
      "precipitation_probability_max",
      "wind_speed_10m_max",
      "wind_gusts_10m_max",
      "sunrise",
      "sunset"
    ].join(",")
  });

  const response = await fetch(`${API_URL}?${params.toString()}&_=${Date.now()}`, {
    headers: {
      accept: "application/json",
      "user-agent": "discord-scheduler/1.0",
      "cache-control": "no-cache"
    }
  });

  if (!response.ok) {
    throw new Error(`Open-Meteo respondeu HTTP ${response.status}`);
  }

  return response.json();
}

function weatherInfo(code) {
  if (code === 0) return ["☀️", "Céu limpo"];
  if (code === 1) return ["🌤️", "Predominantemente limpo"];
  if (code === 2) return ["⛅", "Parcialmente nublado"];
  if (code === 3) return ["☁️", "Nublado"];
  if ([45, 48].includes(code)) return ["🌫️", "Neblina"];
  if ([51, 53, 55, 56, 57].includes(code)) return ["🌦️", "Garoa"];
  if ([61, 63, 66].includes(code)) return ["🌧️", "Chuva"];
  if ([65, 67].includes(code)) return ["🌧️", "Chuva forte"];
  if ([80, 81].includes(code)) return ["🌦️", "Pancadas de chuva"];
  if (code === 82) return ["⛈️", "Pancadas fortes"];
  if (code === 95) return ["⛈️", "Trovoadas"];
  if ([96, 99].includes(code)) return ["⛈️", "Tempestade com granizo"];
  return ["🌡️", "Condição não classificada"];
}

function embedColor(code) {
  if ([82, 95, 96, 99].includes(code)) return 0xe74c3c;
  if ([51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81].includes(code)) {
    return 0x3498db;
  }
  if ([2, 3, 45, 48].includes(code)) return 0x95a5a6;
  return 0xf1c40f;
}

function currentHourlyIndex(hourly) {
  const times = Array.isArray(hourly.time) ? hourly.time : [];
  const target = new Intl.DateTimeFormat("sv-SE", {
    timeZone: TIMEZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    hourCycle: "h23"
  })
    .format(new Date())
    .replace(" ", "T") + ":00";

  const index = times.findIndex(time => String(time) >= target);
  return index >= 0 ? index : 0;
}

function automaticAlerts(data, hourIndex) {
  const alerts = [];
  const current = object(data.current);
  const hourly = object(data.hourly);

  const code = Number(current.weather_code ?? 0);
  const apparent = Number(current.apparent_temperature ?? 0);
  const humidity = Number(current.relative_humidity_2m ?? 100);
  const precip = Number(current.precipitation ?? 0);
  const gust = Number(current.wind_gusts_10m ?? 0);
  const nextProb = windowMax(hourly.precipitation_probability, hourIndex, 3);
  const nextPrecip = windowMax(hourly.precipitation, hourIndex, 3);
  const nextGust = windowMax(hourly.wind_gusts_10m, hourIndex, 3);
  const nextCodes = (hourly.weather_code ?? [])
    .slice(hourIndex, hourIndex + 3)
    .map(Number);

  if ([95, 96, 99].includes(code) || nextCodes.some(value => [95, 96, 99].includes(value))) {
    alerts.push("⛈️ **Tempestade/trovoadas:** condição atual ou prevista nas próximas horas.");
  }

  if (precip >= 7 || nextPrecip >= 7) {
    alerts.push("🌧️ **Chuva forte:** volume horário elevado detectado na previsão.");
  } else if (nextProb >= 80 && nextPrecip >= 2) {
    alerts.push(`☔ **Chuva muito provável:** chance de precipitação de ${n(nextProb, 0)}% nas próximas horas.`);
  }

  if (Math.max(gust, nextGust) >= 60) {
    alerts.push(`💨 **Rajadas fortes:** podem atingir cerca de ${n(Math.max(gust, nextGust), 0)} km/h.`);
  }

  if (apparent >= 35) {
    alerts.push(`🥵 **Calor intenso:** sensação térmica de ${n(apparent)}°C.`);
  }

  if (humidity <= 30) {
    alerts.push(`🏜️ **Umidade muito baixa:** umidade relativa em ${n(humidity, 0)}%.`);
  }

  return alerts;
}

function dailyAlerts(data) {
  const daily = object(data.daily);
  const prob = Number(daily.precipitation_probability_max?.[0] ?? 0);
  const precip = Number(daily.precipitation_sum?.[0] ?? 0);
  const gust = Number(daily.wind_gusts_10m_max?.[0] ?? 0);
  const apparent = Number(daily.apparent_temperature_max?.[0] ?? 0);
  const code = Number(daily.weather_code?.[0] ?? 0);
  const alerts = [];

  if ([95, 96, 99].includes(code)) {
    alerts.push("⛈️ Possibilidade de tempestade/trovoadas ao longo do dia.");
  }

  if (precip >= 20) {
    alerts.push(`🌧️ Volume de chuva diário elevado: cerca de ${n(precip)} mm.`);
  } else if (prob >= 80) {
    alerts.push(`☔ Chance alta de chuva: ${n(prob, 0)}%.`);
  }

  if (gust >= 60) {
    alerts.push(`💨 Rajadas podem chegar a ${n(gust, 0)} km/h.`);
  }

  if (apparent >= 35) {
    alerts.push(`🥵 Sensação térmica máxima próxima de ${n(apparent)}°C.`);
  }

  return alerts;
}

function nextHoursText(data, start, count) {
  const hourly = object(data.hourly);
  const lines = [];

  for (let index = start; index < Math.min(start + count, hourly.time?.length ?? 0); index++) {
    const [icon, label] = weatherInfo(Number(hourly.weather_code?.[index] ?? 0));
    lines.push(
      `${icon} **${formatHour(hourly.time[index])}** — ` +
      `${n(hourly.temperature_2m?.[index])}°C • chuva ` +
      `${pct(hourly.precipitation_probability?.[index])} • ${label}`
    );
  }

  return lines.length ? lines.join("\n") : "Sem dados horários disponíveis.";
}

function windowMax(values, start, length) {
  const rows = Array.isArray(values)
    ? values.slice(start, start + length).map(Number).filter(Number.isFinite)
    : [];

  return rows.length ? Math.max(...rows) : 0;
}

function n(value, decimals = 1) {
  return Number.isFinite(Number(value))
    ? Number(value).toFixed(decimals).replace(".", ",")
    : "—";
}

function pct(value) {
  return Number.isFinite(Number(value))
    ? `${Number(value).toFixed(0).replace(".", ",")}%`
    : "—";
}

function object(value) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value
    : {};
}