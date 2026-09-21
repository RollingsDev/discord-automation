import { publishLiturgy } from "./liturgy.js";
import { publishDailyWeather, publishHourlyWeather } from "./weather.js";
import { saoPauloParts } from "./time.js";

const DAILY_START_HOUR = 6;
const DAILY_END_HOUR = 12;

export default {
  async fetch(request) {
    const url = new URL(request.url);

    if (request.method === "GET" && url.pathname === "/health") {
      return Response.json({
        ok: true,
        service: "discord-scheduler",
        cron: "17 * * * *",
        timezone: "America/Sao_Paulo"
      });
    }

    return new Response("Discord Scheduler online.", { status: 200 });
  },

  async scheduled(controller, env, ctx) {
    const scheduledDate = new Date(controller.scheduledTime ?? Date.now());

    ctx.waitUntil(runScheduled(env, scheduledDate));
  }
};

async function runScheduled(env, now) {
  if (!env.SCHEDULER_STATE) {
    throw new Error(
      "Binding KV SCHEDULER_STATE não configurado no Cloudflare Worker."
    );
  }

  const local = saoPauloParts(now);
  const errors = [];

  try {
    await publishHourlyWeather(env);
    console.log(`Clima horário publicado para ${local.date} ${local.hour}h.`);
  } catch (error) {
    console.error("Falha no clima horário:", error);
    errors.push(error);
  }

  if (local.hour >= DAILY_START_HOUR && local.hour <= DAILY_END_HOUR) {
    try {
      await runOnce(
        env.SCHEDULER_STATE,
        `clima-daily:${local.date}`,
        async () => publishDailyWeather(env)
      );
    } catch (error) {
      console.error("Falha na previsão diária:", error);
      errors.push(error);
    }

    try {
      await runOnce(
        env.SCHEDULER_STATE,
        `liturgia:${local.date}`,
        async () => publishLiturgy(env, now)
      );
    } catch (error) {
      console.error("Falha na liturgia:", error);
      errors.push(error);
    }
  }

  if (errors.length) {
    throw new AggregateError(errors, "Uma ou mais tarefas agendadas falharam.");
  }
}

async function runOnce(kv, key, callback) {
  const alreadyDone = await kv.get(key);

  if (alreadyDone) {
    console.log(`${key} já concluído; ignorando.`);
    return false;
  }

  await callback();

  await kv.put(
    key,
    JSON.stringify({
      completed_at: new Date().toISOString()
    }),
    {
      expirationTtl: 60 * 60 * 24 * 14
    }
  );

  console.log(`${key} concluído e registrado.`);
  return true;
}