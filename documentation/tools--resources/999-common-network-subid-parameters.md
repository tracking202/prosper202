# Common Network SubID Parameters

Find your network's SubID field, then put Prosper202's `[[subid]]` in it on your offer URL. Prosper202 replaces `[[subid]]` with a different click ID on every click, and your network sends that ID back in its postback so the conversion is credited to the click.

- Keep `[[subid]]` exactly as written, and leave your affiliate ID and the other URL parameters as the network gave them.
- Put nothing else in that field: the network sends the whole value back, and the postback matches only the bare click ID. Put `[[c1]]` and other tokens in fields of their own.
- Put the field before a deep link's destination URL (Admitad, Awin).
- Short links and merchant-specific formats may need the network's own link generator.
- If your network is not listed, ask your affiliate manager which field takes a unique external click ID, and which postback token returns it.

| Network or platform | Field | Also | Notes |
|---|---|---|---|
| ADCELL | `subId=` | | For current t.adcell.com links. Older bid-based links use another format; regenerate them in ADCELL. |
| AdCombo | `subacc=` | `subacc2` | Return the same subaccount in your postback. |
| Admitad | `subid=` | `subid1`–`subid4` | Add tracking fields before the destination URL. |
| Adrecord | Confirm with support | | Adrecord joined Adtraction Group; confirm your account's tracking format before editing links. |
| Affise | `sub1=` | `sub2`–`sub8` | Affise Performance links, not Affise MMP. Higher-numbered fields depend on the network's plan. |
| Amazon Associates | `tag=` (account tracking ID) | | `tag` identifies your Associates account: keep it, do not replace it with `[[subid]]`. |
| Awin | `clickref=` | `clickref2`–`clickref6` | `awc` is Awin's own click identifier, not yours. Put ClickRefs before the `ued` destination URL. |
| CAKE | `s2=` | `s3`, `s4`, `s5` | `s1` is reserved for a stable sub-publisher ID. |
| CJ Affiliate | `sid=` | | Keep the existing publisher and advertiser identifiers. |
| ClickBank | `extclid=` | | Unique external click ID, up to 256 characters. `tid` is the older tracking field. |
| ClickDealer | `s2=` | | CAKE-format links with `a` and `c` identifiers; confirm the field for other link types. |
| Commission Factory | `UniqueId=` | | Keep the capital U and I. |
| CPAlead | `subid=` | `subid2`, `subid3` | Returned in publisher postbacks when available. |
| Everflow | `sub1=` | `sub2`–`sub5` | `sub6`–`sub10` may be available when the network enables them. |
| FlexOffers | `fobs=` | `fobs2`–`fobs5` | FlexOffers uses `fobs`, not `sub1`. |
| HitPath | `c1=` | `c2`, `c3` | On direct links `sid` identifies the campaign; use `c1` for your click reference. |
| impact.com | `subId1=` | `subId2`, `subId3`, `SharedId` | SubIDs are private to you; `SharedId` is also visible to the brand. |
| Involve Asia | Sub ID generator | | Set the Sub ID in Involve Asia's deeplink generator and use the link it outputs. |
| MaxBounty | `s2=` | | CAKE-format links; return `s2` in your conversion postback. |
| Offer18 | `aff_sub1=` | `aff_sub2`–`aff_sub5` | Return the field you use for `[[subid]]` in the postback. |
| Partnerize | `/pubref:` | | A path field, placed before `/destination:`. Expand short links in Partnerize first. |
| Pepperjam / Ascend | Confirm in portal | | Use your portal's link generator to find the click reference field. |
| Post Affiliate Pro | `data1=` | `data2` | Default names; merchants can rename them. `a_aid` identifies the affiliate, not the SubID. |
| Rakuten Advertising | `u1=` | | The Signature member ID field; `u2` and `u3` may not behave like `u1`. |
| ShareASale (legacy) | `afftrack=` | | ShareASale closed on October 6, 2025; build new links on Awin with `clickref`. |
| Trackier | `p1=` | `p2`–`p5` | `p1` is the publisher click ID. |
| Tradedoubler | `epi=` | | EPI reference on Link Matrix program links. |
| TUNE / HasOffers | `aff_sub=` | `aff_sub2`–`aff_sub5` | Match the field you use in your postback. |
| Webgains | `clickref=` | | Truncated at 255 characters. `wgexpiry` is not a SubID field. |

The same list, with a link builder, is at [Common Network SubID Parameters](https://prosper.tracking202.com/documentation/tools-and-resources/common-network-subid-parameters).
