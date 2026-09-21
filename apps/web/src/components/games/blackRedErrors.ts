export function blackRedPlayErrorMessage(code: string, serverMessage?: string): string {
  if (serverMessage && serverMessage !== code && !serverMessage.includes("BLACKRED_REQUEST_FAILED")) {
    return serverMessage;
  }
  switch (code) {
    case "INSUFFICIENT_PLAY_BALANCE":
      return "Your stake is higher than your Play Balance. Please top up your wallet or lower the stake.";
    case "LIMIT_REACHED":
      return "You've reached your daily play limit. You can review or adjust your limits in Account Settings.";
    case "EXCLUDED":
      return "A cool-off or self-exclusion period is currently active on your account. Withdrawals remain available.";
    case "LOCATION_UNVERIFIED":
    case "VPN_OR_PROXY_DETECTED":
      return "We couldn't verify your location in Nigeria. Please turn off any VPN or proxy and try again.";
    case "STATE_NOT_LICENSED":
      return "BlackRed isn't currently licensed in your state.";
    case "GAME_UNAVAILABLE":
      return "This game is temporarily undergoing maintenance. Please check back in a few minutes.";
    default:
      return "Could not place that ticket. Please check your connection and try again.";
  }
}
