import { LOL_COMMAND, TFT_COMMAND } from "./commands.js";
import { handleLolLive, handleTftLive, userErrorResponse } from "./riot-live.js";
import { handleTftSnapshot } from "./tft-snapshot.js";

const JSON_HEADERS = { "content-type": "application/json; charset=utf-8" };

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (request.method === "GET" && url.pathname === "/health") {
      return json({
        ok: true,
        service: "oraculo-riot",
        commands: ["/lol", "/tft"],
        lol_key: Boolean(env.RIOT_API_KEY),
        tft_key: Boolean(env.RIOT_TFT_API_KEY)
      });
    }

    if (request.method !== "POST") {
      return new Response("Oráculo Riot online.", { status: 200 });
    }

    const signature = request.headers.get("x-signature-ed25519");
    const timestamp = request.headers.get("x-signature-timestamp");
    const rawBody = await request.text();

    if (
      !signature ||
      !timestamp ||
      !env.DISCORD_PUBLIC_KEY ||
      !(await verifyDiscordRequest(env.DISCORD_PUBLIC_KEY, signature, timestamp, rawBody))
    ) {
      return new Response("invalid request signature", { status: 401 });
    }

    let interaction;
    try {
      interaction = JSON.parse(rawBody);
    } catch {
      return new Response("invalid json", { status: 400 });
    }

    if (interaction.type === 1) return json({ type: 1 });

    if (interaction.type !== 2) {
      return interactionMessage("Interação não reconhecida.", true);
    }

    const commandName = interaction.data?.name;
    if (![LOL_COMMAND.name, TFT_COMMAND.name].includes(commandName)) {
      return interactionMessage("Comando não reconhecido pelo Oráculo Riot.", true);
    }

    if (
      env.DISCORD_GUILD_ID &&
      interaction.guild_id &&
      interaction.guild_id !== env.DISCORD_GUILD_ID
    ) {
      return interactionMessage("Este Oráculo está configurado para outro servidor.", true);
    }

    if (commandName === "lol" && !channelAllowed(interaction, env.DISCORD_LOL_CHANNEL_ID)) {
      return interactionMessage("Use /lol no canal configurado para League of Legends.", true);
    }

    if (commandName === "tft" && !channelAllowed(interaction, env.DISCORD_TFT_CHANNEL_ID)) {
      return interactionMessage("Use /tft no canal configurado para TFT.", true);
    }

    ctx.waitUntil(handleCommand(interaction, env));
    return json({ type: 5 });
  }
};

async function handleCommand(interaction, env) {
  try {
    const commandName = interaction.data?.name;
    const subcommand = interaction.data?.options?.[0];
    let response;

    if (commandName === "lol") {
      response = await handleLolLive(subcommand, env);
    } else if (["player", "partida"].includes(subcommand?.name)) {
      response = await handleTftLive(subcommand, env);
    } else {
      response = await handleTftSnapshot(subcommand, env);
    }

    await editOriginal(interaction, response);
  } catch (error) {
    console.error(error);

    const friendly = userErrorResponse(error);
    await editOriginal(
      interaction,
      friendly ?? {
        content: "⚠️ Não consegui consultar os dados agora. Tente novamente em alguns segundos.",
        allowed_mentions: { parse: [] }
      }
    );
  }
}

function channelAllowed(interaction, expectedChannelId) {
  if (!expectedChannelId) return true;
  if (!interaction.channel_id) return true;
  return interaction.channel_id === expectedChannelId;
}

function interactionMessage(content, ephemeral = false) {
  return json({
    type: 4,
    data: {
      content,
      flags: ephemeral ? 64 : 0,
      allowed_mentions: { parse: [] }
    }
  });
}

async function editOriginal(interaction, data) {
  const url = `https://discord.com/api/v10/webhooks/${interaction.application_id}/${interaction.token}/messages/@original`;
  const response = await fetch(url, {
    method: "PATCH",
    headers: JSON_HEADERS,
    body: JSON.stringify(data)
  });

  if (!response.ok) {
    throw new Error(`Falha ao responder interação: ${response.status} ${await response.text()}`);
  }
}

async function verifyDiscordRequest(publicKeyHex, signatureHex, timestamp, body) {
  try {
    const publicKey = await crypto.subtle.importKey(
      "raw",
      hexToBytes(publicKeyHex),
      { name: "Ed25519" },
      false,
      ["verify"]
    );

    return crypto.subtle.verify(
      { name: "Ed25519" },
      publicKey,
      hexToBytes(signatureHex),
      new TextEncoder().encode(timestamp + body)
    );
  } catch (error) {
    console.error("Falha na validação Ed25519", error);
    return false;
  }
}

function hexToBytes(hex) {
  const clean = String(hex ?? "").trim();
  if (!/^[0-9a-f]+$/i.test(clean) || clean.length % 2 !== 0) {
    throw new Error("Hex inválido.");
  }

  const result = new Uint8Array(clean.length / 2);
  for (let index = 0; index < clean.length; index += 2) {
    result[index / 2] = Number.parseInt(clean.slice(index, index + 2), 16);
  }
  return result;
}

function json(payload, status = 200) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: JSON_HEADERS
  });
}
