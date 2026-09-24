import { COMMANDS } from "../src/commands.js";

const appId = process.env.DISCORD_APP_ID?.trim();
const guildId = process.env.DISCORD_GUILD_ID?.trim();
const botToken = process.env.DISCORD_BOT_TOKEN?.trim();

if (!appId || !guildId || !botToken) {
  throw new Error("Configure DISCORD_APP_ID, DISCORD_GUILD_ID e DISCORD_BOT_TOKEN.");
}

const url = `https://discord.com/api/v10/applications/${appId}/guilds/${guildId}/commands`;

for (const definition of COMMANDS) {
  const response = await fetch(url, {
    method: "POST",
    headers: {
      Authorization: `Bot ${botToken}`,
      "Content-Type": "application/json"
    },
    body: JSON.stringify(definition)
  });

  const body = await response.text();
  if (!response.ok) {
    throw new Error(`Discord respondeu ${response.status} ao registrar /${definition.name}: ${body}`);
  }

  const command = JSON.parse(body);
  console.log(`Comando /${command.name} registrado no servidor ${guildId}. ID: ${command.id}`);
}
