export function blackRedPlayErrorMessage(code: string): string {
  switch (code) {
    case "INSUFFICIENT_PLAY_BALANCE":
      return "Your stake is higher than your Play Balance.";
    case "LIMIT_REACHED":
      return "You've reached one of your play limits. Check Responsible Play for details.";
    case "EXCLUDED":
      return "Play is currently unavailable on this account.";
    case "LOCATION_UNVERIFIED":
    case "VPN_OR_PROXY_DETECTED":
      return "We couldn't verify your location. Disable any VPN and try again.";
    case "STATE_NOT_LICENSED":
      return "BlackRed isn't licensed in your current state.";
    case "GAME_UNAVAILABLE":
      return "BlackRed is temporarily unavailable.";
    default:
      return "Could not place that ticket. Please try again.";
  }
}
