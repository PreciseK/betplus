"use client";

import { doorsGateway } from "@betplus/api-client";
import DoorsDemo from "./DoorsDemo";

export function ConnectedDoors() { return <DoorsDemo gateway={doorsGateway} />; }
