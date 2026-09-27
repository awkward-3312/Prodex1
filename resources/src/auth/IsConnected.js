import { cookieIsKey } from "../platform/storage";

export default (to, from, next) => {
  let accessToken = cookieIsKey("Stocky_token");
  if (accessToken) {
     next("/app/dashboard");
  } else {
    return next();
  }
};
